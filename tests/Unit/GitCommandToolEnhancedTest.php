<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Tools\GitCommandTool;
use Yousef\LaraOrcest\Tools\Support\PathGuard;

class GitCommandToolEnhancedTest extends TestCase
{
    protected string $tempDir;
    protected PathGuard $pathGuard;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/lara_orcest_git_test_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0755, true);

        // Initialize Git repo with an initial commit on main
        $this->runProcess(['git', 'init', '-b', 'main'], $this->tempDir);
        $this->runProcess(['git', 'config', 'user.name', 'LaraOrcest Test'], $this->tempDir);
        $this->runProcess(['git', 'config', 'user.email', 'test@example.com'], $this->tempDir);

        file_put_contents($this->tempDir . '/README.md', "# Test Repo\n");
        $this->runProcess(['git', 'add', 'README.md'], $this->tempDir);
        $this->runProcess(['git', 'commit', '-m', 'Initial commit'], $this->tempDir);

        $this->pathGuard = new PathGuard($this->tempDir);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            $todo($fileinfo->getRealPath());
        }
        rmdir($this->tempDir);
    }

    protected function runProcess(array $command, string $cwd): Process
    {
        $process = new Process($command, $cwd);
        $process->mustRun();

        return $process;
    }

    public function test_tool_metadata_and_schema(): void
    {
        $tool = new GitCommandTool($this->tempDir, pathGuard: $this->pathGuard);

        $this->assertSame('git_command', $tool->getName());
        $this->assertNotEmpty($tool->getDescription());
        $this->assertFalse($tool->isDryRun());

        $schema = $tool->getSchema();
        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('action', $schema['properties']);
        $this->assertContains('action', $schema['required']);
    }

    public function test_dry_run_mode_actions(): void
    {
        $tool = new GitCommandTool($this->tempDir, dryRun: true, pathGuard: $this->pathGuard);

        $status = $tool->execute(['action' => 'status']);
        $this->assertTrue($status['dry_run']);
        $this->assertTrue($status['clean']);
        $this->assertSame('dry-run-branch', $status['branch']);

        $current = $tool->execute(['action' => 'current_branch']);
        $this->assertTrue($current['dry_run']);
        $this->assertSame('dry-run-branch', $current['branch']);

        $branches = $tool->execute(['action' => 'branch']);
        $this->assertTrue($branches['dry_run']);
        $this->assertContains('main', $branches['branches']);

        $diff = $tool->execute(['action' => 'diff']);
        $this->assertTrue($diff['dry_run']);
        $this->assertStringContainsString('[DRY-RUN]', $diff['diff']);

        $branch = $tool->execute(['action' => 'create_branch', 'branch' => 'feature/dry-run']);
        $this->assertTrue($branch['dry_run']);
        $this->assertSame('feature/dry-run', $branch['branch']);

        $stage = $tool->execute(['action' => 'stage_files', 'files' => ['README.md']]);
        $this->assertTrue($stage['dry_run']);
        $this->assertSame(['README.md'], $stage['staged']);

        $commit = $tool->execute(['action' => 'commit', 'message' => 'feat: dry run commit']);
        $this->assertTrue($commit['dry_run']);
        $this->assertTrue($commit['committed']);
        $this->assertSame('dry-run-hash', $commit['commit_hash']);
    }

    public function test_status_and_current_branch_when_clean(): void
    {
        $tool = new GitCommandTool($this->tempDir, pathGuard: $this->pathGuard);

        $status = $tool->execute(['action' => 'status']);
        $this->assertTrue($status['clean']);
        $this->assertSame('main', $status['branch']);
        $this->assertSame([], $status['modified']);
        $this->assertSame([], $status['untracked']);

        $current = $tool->execute(['action' => 'current_branch']);
        $this->assertSame('main', $current['branch']);
    }

    public function test_status_detects_untracked_and_modified_files(): void
    {
        $tool = new GitCommandTool($this->tempDir, pathGuard: $this->pathGuard);

        file_put_contents($this->tempDir . '/README.md', "# Modified Content\n");
        file_put_contents($this->tempDir . '/newfile.txt', "hello\n");

        $status = $tool->execute(['action' => 'status']);
        $this->assertFalse($status['clean']);
        $this->assertContains('README.md', $status['modified']);
        $this->assertContains('newfile.txt', $status['untracked']);
    }

    public function test_branch_listing(): void
    {
        $tool = new GitCommandTool($this->tempDir, pathGuard: $this->pathGuard);

        $branches = $tool->execute(['action' => 'branch']);
        $this->assertSame('main', $branches['current']);
        $this->assertContains('main', $branches['branches']);
    }

    public function test_create_branch_and_commit_workflow(): void
    {
        $tool = new GitCommandTool($this->tempDir, pathGuard: $this->pathGuard);

        // Create feature branch
        $createRes = $tool->execute([
            'action' => 'create_branch',
            'branch' => 'feature/user-auth',
        ]);
        $this->assertSame('feature/user-auth', $createRes['branch']);
        $this->assertTrue($createRes['created']);

        $current = $tool->execute(['action' => 'current_branch']);
        $this->assertSame('feature/user-auth', $current['branch']);

        // Create a new file
        file_put_contents($this->tempDir . '/auth.php', "<?php // auth\n");

        // Stage file
        $stageRes = $tool->execute([
            'action' => 'stage_files',
            'files' => ['auth.php'],
        ]);
        $this->assertSame(['auth.php'], $stageRes['staged']);

        // Commit changes
        $commitRes = $tool->execute([
            'action' => 'commit',
            'message' => 'feat(auth): add auth module',
        ]);
        $this->assertTrue($commitRes['committed']);
        $this->assertSame('feat(auth): add auth module', $commitRes['message']);
        $this->assertNotEmpty($commitRes['commit_hash']);
    }

    public function test_blocks_direct_commit_to_protected_branch(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Committing directly to protected branch [main] is forbidden');

        $tool = new GitCommandTool($this->tempDir, pathGuard: $this->pathGuard);
        $tool->execute([
            'action' => 'commit',
            'message' => 'Illegal direct commit',
        ]);
    }

    public function test_blocks_checkout_or_creation_of_protected_branch(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Direct checkout/creation of protected branch [main] is forbidden');

        $tool = new GitCommandTool($this->tempDir, pathGuard: $this->pathGuard);
        $tool->execute([
            'action' => 'create_branch',
            'branch' => 'main',
        ]);
    }

    public function test_blocks_invalid_branch_name_format(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid branch name format');

        $tool = new GitCommandTool($this->tempDir, pathGuard: $this->pathGuard);
        $tool->execute([
            'action' => 'create_branch',
            'branch' => 'feature/invalid branch; rm -rf /',
        ]);
    }

    public function test_blocks_empty_branch_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Branch name cannot be empty');

        $tool = new GitCommandTool($this->tempDir, pathGuard: $this->pathGuard);
        $tool->execute([
            'action' => 'create_branch',
            'branch' => '   ',
        ]);
    }

    public function test_blocks_dirty_tree_branch_creation_unless_allow_dirty_is_true(): void
    {
        $tool = new GitCommandTool($this->tempDir, pathGuard: $this->pathGuard);

        file_put_contents($this->tempDir . '/dirty.txt', "dirty\n");

        try {
            $tool->execute([
                'action' => 'create_branch',
                'branch' => 'feature/dirty-test',
                'allow_dirty' => false,
            ]);
            $this->fail('Expected RuntimeException for dirty working tree');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Git working tree is dirty', $e->getMessage());
        }

        // Now with allow_dirty: true, it should succeed
        $res = $tool->execute([
            'action' => 'create_branch',
            'branch' => 'feature/dirty-test',
            'allow_dirty' => true,
        ]);
        $this->assertSame('feature/dirty-test', $res['branch']);
    }

    public function test_blocks_destructive_git_operations(): void
    {
        $blockedCommands = [
            'push --force',
            'push -f',
            'reset --hard',
            'clean -f',
            'clean -fd',
            'clean -xdf',
        ];

        $tool = new GitCommandTool($this->tempDir, pathGuard: $this->pathGuard);

        foreach ($blockedCommands as $blocked) {
            try {
                $tool->execute([
                    'action' => 'create_branch',
                    'branch' => 'feature/' . $blocked,
                ]);
                $this->fail("Expected RuntimeException for blocked pattern: {$blocked}");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('is strictly blocked', $e->getMessage());
            }
        }
    }

    public function test_stage_files_denies_sensitive_paths_via_pathguard(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Access to denied path pattern blocked: [.env]');

        $tool = new GitCommandTool($this->tempDir, pathGuard: $this->pathGuard);
        $tool->execute([
            'action' => 'stage_files',
            'files' => ['.env'],
        ]);
    }

    public function test_apply_to_state_updates_run_state_cleanly(): void
    {
        $tool = new GitCommandTool($this->tempDir, pathGuard: $this->pathGuard);
        $state = new RunState(runId: 'run_123', prompt: 'Implement user profile');

        // Apply create_branch
        $result = $tool->applyToState($state, [
            'action' => 'create_branch',
            'branch' => 'feature/profile',
        ]);

        /** @var RunState $updatedState */
        $updatedState = $result['state'];
        $this->assertSame('feature/profile', $updatedState->gitState['feature_branch']);
        $this->assertTrue($updatedState->gitState['branch_created']);

        // Create file & stage
        file_put_contents($this->tempDir . '/profile.php', "<?php // profile\n");
        $tool->execute(['action' => 'stage_files', 'files' => ['profile.php']]);

        // Apply commit
        $commitResult = $tool->applyToState($updatedState, [
            'action' => 'commit',
            'message' => 'feat(profile): add profile screen',
        ]);

        /** @var RunState $stateAfterCommit */
        $stateAfterCommit = $commitResult['state'];
        $this->assertCount(1, $stateAfterCommit->gitState['commits']);
        $this->assertSame('feat(profile): add profile screen', $stateAfterCommit->gitState['commits'][0]['message']);
        $this->assertNotEmpty($stateAfterCommit->gitState['commits'][0]['commit_hash']);
    }
}
