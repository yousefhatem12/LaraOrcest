<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yousef\LaraOrcest\Graph\ScopeConflictDetector;

class ScopeConflictDetectorTest extends TestCase
{
    public function test_normalizes_paths_consistently(): void
    {
        $this->assertEquals('app/Models/User.php', ScopeConflictDetector::normalizePath('./app/Models/User.php'));
        $this->assertEquals('app/Models/User.php', ScopeConflictDetector::normalizePath('app/Models/User.php/'));
        $this->assertEquals('app/Models/User.php', ScopeConflictDetector::normalizePath('app\\Models\\User.php'));
        $this->assertEquals('app/Models/User.php', ScopeConflictDetector::normalizePath('/app//Models///User.php/'));
        $this->assertEquals('', ScopeConflictDetector::normalizePath(''));
    }

    public function test_normalizes_scopes_array_and_removes_duplicates(): void
    {
        $input = [
            'app/Models/User.php',
            './app/Models/User.php/',
            'database/migrations',
            '',
            'database/migrations/',
        ];

        $normalized = ScopeConflictDetector::normalizeScopes($input);
        $this->assertEquals(['app/Models/User.php', 'database/migrations'], $normalized);
    }

    public function test_detects_exact_path_conflicts(): void
    {
        $this->assertTrue(ScopeConflictDetector::pathsOverlap('app/Models/User.php', 'app/Models/User.php'));
        $this->assertTrue(ScopeConflictDetector::pathsOverlap('./app/Models/User.php', 'app/Models/User.php/'));
        $this->assertFalse(ScopeConflictDetector::pathsOverlap('app/Models/User.php', 'app/Models/Post.php'));
    }

    public function test_detects_hierarchical_directory_containment(): void
    {
        // Directory contains file
        $this->assertTrue(ScopeConflictDetector::pathsOverlap('app/Models', 'app/Models/User.php'));
        $this->assertTrue(ScopeConflictDetector::pathsOverlap('app/Models/User.php', 'app/Models'));
        $this->assertTrue(ScopeConflictDetector::pathsOverlap('app', 'app/Http/Controllers/OrderController.php'));

        // Disjoint sibling files in same directory do not overlap
        $this->assertFalse(ScopeConflictDetector::pathsOverlap('app/Models/Order.php', 'app/Models/User.php'));
        // Disjoint directories with similar prefix do not overlap
        $this->assertFalse(ScopeConflictDetector::pathsOverlap('app/Models', 'app/ModelsBackup'));
    }

    public function test_detects_wildcard_glob_overlap(): void
    {
        $this->assertTrue(ScopeConflictDetector::pathsOverlap('routes/*.php', 'routes/api.php'));
        $this->assertTrue(ScopeConflictDetector::pathsOverlap('app/Models/*', 'app/Models/Order.php'));
        $this->assertFalse(ScopeConflictDetector::pathsOverlap('routes/*.php', 'config/app.php'));
    }

    public function test_has_conflict_and_find_conflicting_paths(): void
    {
        $scopeA = ['app/Models/User.php', 'database/migrations'];
        $scopeB = ['app/Models/User.php', 'routes/api.php'];
        $scopeC = ['app/Http/Controllers/UserController.php', 'routes/api.php'];

        $this->assertTrue(ScopeConflictDetector::hasConflict($scopeA, $scopeB));
        $conflicts = ScopeConflictDetector::findConflictingPaths($scopeA, $scopeB);
        $this->assertCount(1, $conflicts);
        $this->assertEquals('app/Models/User.php', $conflicts[0]['path_a']);
        $this->assertEquals('app/Models/User.php', $conflicts[0]['path_b']);

        $this->assertFalse(ScopeConflictDetector::hasConflict($scopeA, $scopeC));
        $this->assertEmpty(ScopeConflictDetector::findConflictingPaths($scopeA, $scopeC));
    }

    public function test_find_colliding_artifacts_across_parallel_branches(): void
    {
        $branchArtifacts = [
            'branch_models' => [
                ['path' => 'app/Models/User.php', 'action' => 'modified', 'summary' => 'Added email cast'],
                ['path' => 'app/Models/Profile.php', 'action' => 'created', 'summary' => 'Created profile model'],
            ],
            'branch_auth' => [
                ['path' => 'app/Models/User.php', 'action' => 'modified', 'summary' => 'Added password hashing hook'],
                ['path' => 'app/Http/Controllers/AuthController.php', 'action' => 'created', 'summary' => 'Auth controller'],
            ],
            'branch_database' => [
                ['path' => 'database/migrations/2026_01_01_create_users_table.php', 'action' => 'created', 'summary' => 'Users table'],
            ],
        ];

        $collisions = ScopeConflictDetector::findCollidingArtifacts($branchArtifacts);

        $this->assertArrayHasKey('app/Models/User.php', $collisions);
        $this->assertCount(2, $collisions['app/Models/User.php']);
        $branchesInvolved = array_column($collisions['app/Models/User.php'], 'branch');
        $this->assertContains('branch_models', $branchesInvolved);
        $this->assertContains('branch_auth', $branchesInvolved);

        // Disjoint artifacts should not be in collisions
        $this->assertArrayNotHasKey('app/Models/Profile.php', $collisions);
        $this->assertArrayNotHasKey('app/Http/Controllers/AuthController.php', $collisions);
    }
}
