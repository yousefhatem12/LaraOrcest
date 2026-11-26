<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Skills;

use Yousef\LaraOrcest\Support\Config;

class SkillRegistry
{
    /**
     * @var array<string, Skill>
     */
    protected array $skills = [];

    protected bool $loaded = false;

    protected string $packageSkillsPath;

    protected string $customSkillsPath;

    protected bool $allowCustom;

    protected bool $enabled;

    protected int $maxActiveSkills;

    public function __construct(
        ?string $packageSkillsPath = null,
        ?string $customSkillsPath = null,
        ?bool $allowCustom = null,
        ?bool $enabled = null,
        ?int $maxActiveSkills = null
    ) {
        $this->packageSkillsPath = $packageSkillsPath ?: (string) (Config::get('lara-orcest.skills.path') ?: __DIR__ . '/../../resources/skills');
        
        $base = Config::basePath();
        $customRel = (string) (Config::get('lara-orcest.skills.custom_path', '.lara-orcest/skills'));
        $this->customSkillsPath = $customSkillsPath ?: ($base . DIRECTORY_SEPARATOR . ltrim($customRel, '/\\'));

        $this->allowCustom = $allowCustom !== null
            ? $allowCustom
            : (bool) Config::get('lara-orcest.skills.allow_custom', true);

        $this->enabled = $enabled !== null
            ? $enabled
            : (bool) Config::get('lara-orcest.skills.enabled', true);

        $this->maxActiveSkills = $maxActiveSkills !== null
            ? $maxActiveSkills
            : (int) Config::get('lara-orcest.skills.max_active_skills', 5);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function isCustomAllowed(): bool
    {
        return $this->allowCustom;
    }

    public function getMaxActiveSkills(): int
    {
        return $this->maxActiveSkills;
    }

    /**
     * Register a skill instance manually.
     */
    public function register(Skill $skill, bool $override = true): self
    {
        $this->ensureLoaded();

        if ($override || ! isset($this->skills[$skill->name])) {
            $this->skills[$skill->name] = $skill;
        }

        return $this;
    }

    /**
     * Unregister a skill by name.
     */
    public function unregister(string $name): self
    {
        $this->ensureLoaded();

        unset($this->skills[$name]);

        return $this;
    }

    /**
     * Check if a skill exists.
     */
    public function has(string $name): bool
    {
        $this->ensureLoaded();

        return isset($this->skills[$name]);
    }

    /**
     * Get a specific skill by name.
     */
    public function get(string $name): ?Skill
    {
        $this->ensureLoaded();

        return $this->skills[$name] ?? null;
    }

    /**
     * Get all registered skills.
     *
     * @return array<string, Skill>
     */
    public function all(): array
    {
        $this->ensureLoaded();

        return $this->skills;
    }

    /**
     * Get all skills applicable to a given agent.
     *
     * @return array<string, Skill>
     */
    public function getForAgent(string $agentKey, ?string $parentAgentKey = null): array
    {
        $this->ensureLoaded();

        $matching = [];
        $configured = (array) Config::get("lara-orcest.skills.agent_skills.{$agentKey}", []);

        foreach ($this->skills as $name => $skill) {
            if ($skill->appliesToAgent($agentKey, $parentAgentKey) || in_array($name, $configured, true)) {
                $matching[$name] = $skill;
            }
        }

        return $matching;
    }

    /**
     * Resolve active skills for an agent, optionally filtered by requested skill names and bounded by max_active_skills.
     *
     * @param  array<int, string>  $requestedNames
     * @return array<string, Skill>
     */
    public function resolveActiveSkills(string $agentKey, array $requestedNames = [], ?string $parentAgentKey = null): array
    {
        if (! $this->enabled) {
            return [];
        }

        $this->ensureLoaded();
        $applicable = $this->getForAgent($agentKey, $parentAgentKey);

        if (empty($requestedNames)) {
            return array_slice($applicable, 0, $this->maxActiveSkills, true);
        }

        $active = [];
        foreach ($requestedNames as $name) {
            if (isset($applicable[$name])) {
                $active[$name] = $applicable[$name];
                if (count($active) >= $this->maxActiveSkills) {
                    break;
                }
            }
        }

        return $active;
    }

    /**
     * Progressive disclosure: lightweight summary of applicable skills for prompt injection.
     *
     * @param  array<int, string>  $activeSkillNames
     */
    public function buildSummaries(string $agentKey, array $activeSkillNames = [], ?string $parentAgentKey = null): string
    {
        if (! $this->enabled) {
            return '';
        }

        $skills = ! empty($activeSkillNames)
            ? $this->resolveActiveSkills($agentKey, $activeSkillNames, $parentAgentKey)
            : $this->getForAgent($agentKey, $parentAgentKey);

        if (empty($skills)) {
            return '';
        }

        $lines = ["AVAILABLE SKILLS & GUIDELINES:"];
        foreach ($skills as $skill) {
            $desc = trim($skill->description);
            $lines[] = "- {$skill->name}: {$desc}";
        }

        return implode("\n", $lines);
    }

    /**
     * Progressive disclosure: full instructions for active skills.
     *
     * @param  array<int, string>  $activeSkillNames
     */
    public function buildInstructions(string $agentKey, array $activeSkillNames = [], ?string $parentAgentKey = null): string
    {
        if (! $this->enabled) {
            return '';
        }

        $skills = $this->resolveActiveSkills($agentKey, $activeSkillNames, $parentAgentKey);
        if (empty($skills)) {
            return '';
        }

        $sections = ["ACTIVE SKILL INSTRUCTIONS:"];
        foreach ($skills as $skill) {
            $toolsStr = ! empty($skill->allowedTools) ? ' (Allowed tools: ' . implode(', ', $skill->allowedTools) . ')' : '';
            $sections[] = "### Skill: {$skill->name}{$toolsStr}\n{$skill->instructions}";
        }

        return implode("\n\n", $sections);
    }

    /**
     * Ensure package and custom skills are loaded once.
     */
    public function loadAll(bool $force = false): self
    {
        if ($this->loaded && ! $force) {
            return $this;
        }

        $this->loaded = true;
        $this->skills = [];
        $this->loadPackageSkills();

        if ($this->allowCustom) {
            $this->loadCustomSkills();
        }

        return $this;
    }

    /**
     * Load skills from the package resources directory.
     */
    public function loadPackageSkills(?string $directory = null): int
    {
        $dir = $directory ?: $this->packageSkillsPath;
        if (! is_dir($dir)) {
            return 0;
        }

        return $this->scanAndLoadDirectory($dir, 'package', override: false);
    }

    /**
     * Load skills from the project custom directory (.lara-orcest/skills/).
     */
    public function loadCustomSkills(?string $directory = null): int
    {
        if (! $this->allowCustom) {
            return 0;
        }

        $dir = $directory ?: $this->customSkillsPath;
        if (! is_dir($dir)) {
            return 0;
        }

        return $this->scanAndLoadDirectory($dir, 'custom', override: true);
    }

    /**
     * Scan directory for *.md files and register.
     */
    protected function scanAndLoadDirectory(string $directory, string $source, bool $override): int
    {
        $files = glob(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '*.md');
        if (! is_array($files)) {
            return 0;
        }

        $count = 0;
        foreach ($files as $file) {
            if (! is_file($file)) {
                continue;
            }

            $content = (string) file_get_contents($file);
            $fallbackName = pathinfo($file, PATHINFO_FILENAME);

            $skill = Skill::parseFromMarkdown($content, $fallbackName, [
                'source' => $source,
                'path' => $file,
            ]);

            $this->register($skill, override: $override);
            $count++;
        }

        return $count;
    }

    protected function ensureLoaded(): void
    {
        if (! $this->loaded) {
            $this->loadAll();
        }
    }
}
