<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yousef\LaraOrcest\Agents\ArchitectAgent;
use Yousef\LaraOrcest\Agents\BackendAgent;
use Yousef\LaraOrcest\Agents\BusinessAnalystAgent;
use Yousef\LaraOrcest\Agents\DatabaseAgent;
use Yousef\LaraOrcest\Agents\FixerAgent;
use Yousef\LaraOrcest\Agents\GitAgent;
use Yousef\LaraOrcest\Agents\PackageManagerAgent;
use Yousef\LaraOrcest\Agents\ProductManagerAgent;
use Yousef\LaraOrcest\Agents\QaAgent;
use Yousef\LaraOrcest\Agents\ReviewerAgent;
use Yousef\LaraOrcest\Agents\SwaggerAgent;
use Yousef\LaraOrcest\Agents\WebScrapingAgent;
use Yousef\LaraOrcest\Agents\Teams\SubAgentRegistry;
use Yousef\LaraOrcest\Skills\SkillRegistry;

class AgentSkillsRegistryTest extends TestCase
{
    protected SkillRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new SkillRegistry();
    }

    public function test_all_lead_agent_classes_resolve_valid_non_empty_skills(): void
    {
        $leadAgents = [
            new BusinessAnalystAgent(),
            new ProductManagerAgent(),
            new ArchitectAgent(),
            new PackageManagerAgent(),
            new DatabaseAgent(),
            new BackendAgent(),
            new QaAgent(),
            new ReviewerAgent(),
            new FixerAgent(),
            new GitAgent(),
            new SwaggerAgent(),
            new WebScrapingAgent(),
        ];

        foreach ($leadAgents as $agent) {
            $agentName = $agent->getName();
            $skills = $agent->getSkills();

            $this->assertNotEmpty(
                $skills,
                "Lead agent [{$agentName}] must have at least one assigned skill."
            );

            // Verify each skill exists in the SkillRegistry and has loadable markdown file
            foreach ($skills as $skillName) {
                $skill = $this->registry->get($skillName);
                $this->assertNotNull(
                    $skill,
                    "Skill [{$skillName}] assigned to lead agent [{$agentName}] must exist in SkillRegistry."
                );
                $this->assertNotEmpty(
                    $skill->instructions,
                    "Skill [{$skillName}] for lead agent [{$agentName}] must have non-empty markdown instructions."
                );
            }
        }
    }

    public function test_all_registered_subagents_resolve_valid_non_empty_skills(): void
    {
        $subRegistry = SubAgentRegistry::createDefault();
        $subAgents = $subRegistry->all();

        $this->assertCount(
            19,
            $subAgents,
            'SubAgentRegistry::createDefault() must contain exactly 19 sub-agents.'
        );

        foreach ($subAgents as $key => $subAgent) {
            $skills = $subAgent->skills();

            $this->assertNotEmpty(
                $skills,
                "Sub-agent [{$key}] must have at least one dedicated skill assigned."
            );

            foreach ($skills as $skillName) {
                $skill = $this->registry->get($skillName);
                $this->assertNotNull(
                    $skill,
                    "Skill [{$skillName}] assigned to sub-agent [{$key}] must exist in SkillRegistry."
                );
                $this->assertNotEmpty(
                    $skill->instructions,
                    "Skill [{$skillName}] for sub-agent [{$key}] must have non-empty markdown instructions."
                );
            }
        }
    }

    public function test_skill_registry_resolves_skills_for_every_agent_key(): void
    {
        $allAgentKeys = [
            // Lead agents
            'business_analyst',
            'product_manager',
            'architect',
            'package_manager_agent',
            'database_agent',
            'backend_agent',
            'qa_agent',
            'reviewer_agent',
            'fixer_agent',
            'git_agent',
            'swagger_agent',
            'web_scraping_agent',
            // Sub-agents
            'domain_rules_agent',
            'backlog_splitter_agent',
            'api_contract_agent',
            'ddd_boundary_agent',
            'schema_planner_agent',
            'laravel_installer_agent',
            'dependency_resolver_agent',
            'structure_scaffolder_agent',
            'migration_agent',
            'model_eloquent_agent',
            'seeder_agent',
            'request_validation_agent',
            'service_action_agent',
            'controller_resource_agent',
            'feature_test_agent',
        ];

        foreach ($allAgentKeys as $agentKey) {
            $skills = $this->registry->getForAgent($agentKey);
            $this->assertNotEmpty(
                $skills,
                "SkillRegistry::getForAgent('{$agentKey}') must return at least one skill."
            );
        }
    }

    public function test_all_skills_in_resources_directory_have_valid_frontmatter_and_instructions(): void
    {
        $skills = $this->registry->all();
        $this->assertGreaterThanOrEqual(20, count($skills));

        foreach ($skills as $name => $skill) {
            $this->assertEquals($name, $skill->name);
            $this->assertNotEmpty($skill->description, "Skill [{$name}] must have a non-empty description.");
            $this->assertNotEmpty($skill->agents, "Skill [{$name}] must list at least one agent in frontmatter.");
            $this->assertNotEmpty($skill->instructions, "Skill [{$name}] must have markdown content.");
        }
    }

    public function test_config_agent_skills_covers_all_lead_and_sub_agents_with_loadable_markdown_files(): void
    {
        $config = require dirname(__DIR__, 2) . '/config/lara-orcest.php';
        $agentSkills = $config['skills']['agent_skills'] ?? [];

        $leadAgents = [
            new BusinessAnalystAgent(),
            new ProductManagerAgent(),
            new ArchitectAgent(),
            new PackageManagerAgent(),
            new DatabaseAgent(),
            new BackendAgent(),
            new QaAgent(),
            new ReviewerAgent(),
            new FixerAgent(),
            new GitAgent(),
            new SwaggerAgent(),
            new WebScrapingAgent(),
        ];

        $expectedKeys = array_map(fn ($agent) => $agent->getName(), $leadAgents);
        foreach (SubAgentRegistry::createDefault()->all() as $subKey => $subAgent) {
            $expectedKeys[] = $subKey;
        }
        $expectedKeys = array_values(array_unique($expectedKeys));

        $skillsDir = dirname(__DIR__, 2) . '/resources/skills';

        foreach ($expectedKeys as $agentKey) {
            $this->assertArrayHasKey(
                $agentKey,
                $agentSkills,
                "config/lara-orcest.php skills.agent_skills must define an entry for [{$agentKey}]."
            );
            $this->assertNotEmpty(
                $agentSkills[$agentKey],
                "config/lara-orcest.php skills.agent_skills[{$agentKey}] must not be empty."
            );

            foreach ($agentSkills[$agentKey] as $skillName) {
                $this->assertFileExists(
                    "{$skillsDir}/{$skillName}.md",
                    "Missing markdown skill file [{$skillName}.md] for agent [{$agentKey}]."
                );
                $skill = $this->registry->get($skillName);
                $this->assertNotNull($skill);
                $this->assertNotEmpty($skill->instructions);
            }

            $summaries = $this->registry->buildSummaries($agentKey);
            $this->assertNotEmpty($summaries, "SkillRegistry::buildSummaries('{$agentKey}') must be non-empty.");
        }
    }
}
