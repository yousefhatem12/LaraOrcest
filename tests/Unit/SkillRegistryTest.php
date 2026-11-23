<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yousef\LaraOrcest\Skills\Skill;
use Yousef\LaraOrcest\Skills\SkillRegistry;

class SkillRegistryTest extends TestCase
{
    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/lara_orcest_skills_test_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*');
            if (is_array($files)) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
            @rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    public function test_skill_dto_properties_and_parsing_frontmatter(): void
    {
        $markdown = <<<MD
---
name: custom-testing-skill
description: Guideline for unit testing with Pest and PHPUnit.
agents:
  - backend_agent
  - qa_agent
allowed_tools:
  - file_reader
  - patch_writer
tags:
  - testing
  - pest
---

# Custom Testing Instructions
Always assert exact status codes and database states.
MD;

        $skill = Skill::parseFromMarkdown($markdown, 'fallback-name');

        $this->assertEquals('custom-testing-skill', $skill->name);
        $this->assertEquals('Guideline for unit testing with Pest and PHPUnit.', $skill->description);
        $this->assertEquals(['backend_agent', 'qa_agent'], $skill->agents);
        $this->assertEquals(['file_reader', 'patch_writer'], $skill->allowedTools);
        $this->assertEquals(['testing', 'pest'], $skill->tags);
        $this->assertStringContainsString('Always assert exact status codes', $skill->instructions);
        $this->assertTrue($skill->appliesToAgent('backend_agent'));
        $this->assertTrue($skill->appliesToAgent('qa_agent'));
        $this->assertFalse($skill->appliesToAgent('architect'));
        $this->assertTrue($skill->permitsTool('file_reader'));
        $this->assertFalse($skill->permitsTool('artisan_runner'));
    }

    public function test_skill_dto_fallback_when_frontmatter_missing(): void
    {
        $markdown = "# Simple Eloquent Guidelines\n\nEnsure relations have return type hints.";
        $skill = Skill::parseFromMarkdown($markdown, 'eloquent-guidelines');

        $this->assertEquals('eloquent-guidelines', $skill->name);
        $this->assertEquals('Simple Eloquent Guidelines', $skill->description);
        $this->assertEquals(['*'], $skill->agents);
        $this->assertTrue($skill->appliesToAgent('any_agent'));
        $this->assertStringContainsString('Ensure relations have return type hints', $skill->instructions);
    }

    public function test_skill_registry_loads_package_defaults(): void
    {
        $registry = new SkillRegistry();
        $registry->loadAll();

        $this->assertTrue($registry->has('laravel-strict-standards'));
        $this->assertTrue($registry->has('scoped-file-editing'));
        $this->assertTrue($registry->has('database-migration-hygiene'));

        $strict = $registry->get('laravel-strict-standards');
        $this->assertNotNull($strict);
        $this->assertTrue($strict->appliesToAgent('backend_agent'));
        $this->assertTrue($strict->appliesToAgent('database_agent'));
    }

    public function test_skill_registry_loads_custom_skills_and_overrides(): void
    {
        $customMd = <<<MD
---
name: custom-project-rules
description: Project-specific domain rules for tenant isolation.
agents:
  - backend_agent
---
Always verify tenant_id scope on queries.
MD;
        file_put_contents($this->tempDir . '/custom-project-rules.md', $customMd);

        $overrideMd = <<<MD
---
name: laravel-strict-standards
description: Custom override for strict standards.
agents:
  - backend_agent
---
Overridden project instructions.
MD;
        file_put_contents($this->tempDir . '/laravel-strict-standards.md', $overrideMd);

        $registry = new SkillRegistry(
            customSkillsPath: $this->tempDir,
            allowCustom: true
        );
        $registry->loadAll();

        $this->assertTrue($registry->has('custom-project-rules'));
        $this->assertEquals('Project-specific domain rules for tenant isolation.', $registry->get('custom-project-rules')->description);

        // Verify override
        $strict = $registry->get('laravel-strict-standards');
        $this->assertNotNull($strict);
        $this->assertEquals('Custom override for strict standards.', $strict->description);
        $this->assertStringContainsString('Overridden project instructions.', $strict->instructions);
    }

    public function test_skill_registry_ignores_custom_skills_when_allow_custom_is_false(): void
    {
        $customMd = <<<MD
---
name: forbidden-custom-skill
description: Should not load.
---
Secret custom skill.
MD;
        file_put_contents($this->tempDir . '/forbidden-custom-skill.md', $customMd);

        $registry = new SkillRegistry(
            customSkillsPath: $this->tempDir,
            allowCustom: false
        );
        $registry->loadAll();

        $this->assertFalse($registry->has('forbidden-custom-skill'));
    }

    public function test_progressive_disclosure_summaries_vs_instructions(): void
    {
        $registry = new SkillRegistry();
        $registry->loadAll();

        $summaries = $registry->buildSummaries('backend_agent');
        $this->assertStringContainsString('AVAILABLE SKILLS & GUIDELINES:', $summaries);
        $this->assertStringContainsString('laravel-strict-standards:', $summaries);
        // Summaries should NOT contain full markdown bodies
        $this->assertStringNotContainsString('When implementing or modifying code in this Laravel application', $summaries);

        // Full instructions for specific requested active skill
        $instructions = $registry->buildInstructions('backend_agent', ['laravel-strict-standards']);
        $this->assertStringContainsString('ACTIVE SKILL INSTRUCTIONS:', $instructions);
        $this->assertStringContainsString('### Skill: laravel-strict-standards', $instructions);
        $this->assertStringContainsString('declare(strict_types=1);', $instructions);
    }

    public function test_max_active_skills_limit(): void
    {
        $registry = new SkillRegistry(maxActiveSkills: 1);
        $registry->loadAll();

        $active = $registry->resolveActiveSkills('backend_agent');
        $this->assertCount(1, $active);
    }
}
