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
     * Aggregate allowed explore paths (explore_paths + modify_paths) for an agent's active skills.
     *
     * @param  array<int, string>  $requestedNames
     * @return array<int, string>
     */
    public function explorePaths(string $agentKey, array $requestedNames = [], ?string $parentAgentKey = null): array
    {
        return $this->resolveExplorePaths($agentKey, $requestedNames, $parentAgentKey);
    }

    /**
     * Aggregate allowed explore paths (explore_paths + modify_paths) for an agent's active skills.
     *
     * @param  array<int, string>  $requestedNames
     * @return array<int, string>
     */
    public function resolveExplorePaths(string $agentKey, array $requestedNames = [], ?string $parentAgentKey = null): array
    {
        $skills = $this->resolveActiveSkills($agentKey, $requestedNames, $parentAgentKey);

        return self::aggregateExplorePaths($skills);
    }

    /**
     * Aggregate allowed modify paths for an agent's active skills.
     *
     * @param  array<int, string>  $requestedNames
     * @return array<int, string>
     */
    public function modifyPaths(string $agentKey, array $requestedNames = [], ?string $parentAgentKey = null): array
    {
        return $this->resolveModifyPaths($agentKey, $requestedNames, $parentAgentKey);
    }

    /**
     * Aggregate allowed modify paths for an agent's active skills.
     *
     * @param  array<int, string>  $requestedNames
     * @return array<int, string>
     */
    public function resolveModifyPaths(string $agentKey, array $requestedNames = [], ?string $parentAgentKey = null): array
    {
        $skills = $this->resolveActiveSkills($agentKey, $requestedNames, $parentAgentKey);

        return self::aggregateModifyPaths($skills);
    }

    /**
     * Aggregate ignored paths across an agent's active skills.
     *
     * @param  array<int, string>  $requestedNames
     * @return array<int, string>
     */
    public function ignorePaths(string $agentKey, array $requestedNames = [], ?string $parentAgentKey = null): array
    {
        return $this->resolveIgnorePaths($agentKey, $requestedNames, $parentAgentKey);
    }

    /**
     * Aggregate ignored paths across an agent's active skills.
     *
     * @param  array<int, string>  $requestedNames
     * @return array<int, string>
     */
    public function resolveIgnorePaths(string $agentKey, array $requestedNames = [], ?string $parentAgentKey = null): array
    {
        $skills = $this->resolveActiveSkills($agentKey, $requestedNames, $parentAgentKey);

        return self::aggregateIgnorePaths($skills);
    }

    /**
     * Build a compact Allowed File Map (Explore Only vs Can Modify vs Ignore) for prompt injection.
     *
     * @param  array<int, string>  $requestedNames
     * @param  array<int, string>  $agentScopePaths
     */
    public function buildAllowedFileMap(
        string $agentKey,
        array $requestedNames = [],
        ?string $parentAgentKey = null,
        array $agentScopePaths = []
    ): string {
        if (! $this->enabled) {
            return '';
        }

        $skills = $this->resolveActiveSkills($agentKey, $requestedNames, $parentAgentKey);

        return self::buildAllowedFileMapForSkills($skills, $agentScopePaths);
    }

    /**
     * Aggregate explore_paths + modify_paths across a list of Skill instances.
     *
     * @param  array<int|string, Skill>  $skills
     * @return array<int, string>
     */
    public static function aggregateExplorePaths(array $skills): array
    {
        $paths = [];
        foreach ($skills as $skill) {
            if ($skill instanceof Skill) {
                $paths = array_merge($paths, $skill->explorePaths(), $skill->modifyPaths());
            }
        }

        return array_values(array_unique(array_filter($paths, static fn ($p) => is_string($p) && trim($p) !== '')));
    }

    /**
     * Aggregate modify_paths across a list of Skill instances.
     *
     * @param  array<int|string, Skill>  $skills
     * @return array<int, string>
     */
    public static function aggregateModifyPaths(array $skills): array
    {
        $paths = [];
        foreach ($skills as $skill) {
            if ($skill instanceof Skill) {
                $paths = array_merge($paths, $skill->modifyPaths());
            }
        }

        return array_values(array_unique(array_filter($paths, static fn ($p) => is_string($p) && trim($p) !== '')));
    }

    /**
     * Aggregate ignore_paths across a list of Skill instances.
     * Excludes a skill's domain ignore_paths if a sibling active skill explicitly permits that path via explore_paths or modify_paths.
     *
     * @param  array<int|string, Skill>  $skills
     * @return array<int, string>
     */
    public static function aggregateIgnorePaths(array $skills): array
    {
        $skillList = array_values(array_filter($skills, static fn ($sk) => $sk instanceof Skill));
        $paths = [];

        foreach ($skillList as $idx => $skill) {
            foreach ($skill->ignorePaths() as $ignorePattern) {
                if (! is_string($ignorePattern) || trim($ignorePattern) === '') {
                    continue;
                }

                $overriddenBySibling = false;
                foreach ($skillList as $otherIdx => $otherSkill) {
                    if ($otherIdx === $idx) {
                        continue;
                    }
                    $otherAllowed = array_merge($otherSkill->explorePaths(), $otherSkill->modifyPaths());
                    foreach ($otherAllowed as $allowedGlob) {
                        if (self::globCovers($ignorePattern, $allowedGlob)) {
                            $overriddenBySibling = true;
                            break 2;
                        }
                    }
                }

                if (! $overriddenBySibling) {
                    $paths[] = $ignorePattern;
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * Checks whether $broaderGlob covers the namespace/path of $targetGlob.
     */
    protected static function globCovers(string $broaderGlob, string $targetGlob): bool
    {
        $b = ltrim(str_replace('\\', '/', trim($broaderGlob)), './');
        $t = ltrim(str_replace('\\', '/', trim($targetGlob)), './');
        if ($b === $t) {
            return true;
        }

        $guard = new \Yousef\LaraOrcest\Tools\Support\PathGuard();
        if ($guard->globMatches($t, $b)) {
            return true;
        }

        // Convert 'app/**' or 'app/Domains/*/Models/**' into a concrete probe path to check glob coverage
        $probe = preg_replace('/\/\*\*$/', '/__probe__', $t) ?? $t;
        $probe = str_replace(['**', '*'], ['__a__/__b__', '__x__'], $probe);

        return $guard->globMatches($probe, $b);
    }

    /**
     * Build a compact Allowed File Map section directly from resolved Skill instances.
     *
     * @param  array<int|string, Skill>  $skills
     * @param  array<int, string>  $agentScopePaths
     */
    public static function buildAllowedFileMapForSkills(array $skills, array $agentScopePaths = []): string
    {
        if (empty($skills) && empty($agentScopePaths)) {
            return '';
        }

        $explore = self::aggregateExplorePaths($skills);
        $modify = self::aggregateModifyPaths($skills);
        $ignore = self::aggregateIgnorePaths($skills);

        $hasModifyDeclaration = false;
        foreach ($skills as $skill) {
            if ($skill instanceof Skill && $skill->hasDeclaredModifyPaths()) {
                $hasModifyDeclaration = true;
                break;
            }
        }

        if (! empty($agentScopePaths)) {
            if (! $hasModifyDeclaration) {
                $modify = array_values(array_unique($agentScopePaths));
            } elseif (! empty($modify)) {
                // Keep concrete scope paths when agent scope is narrower or combine cleanly for display
                $modify = count($agentScopePaths) <= count($modify) ? array_values(array_unique($agentScopePaths)) : $modify;
            }
        }

        $exploreOnly = array_values(array_diff($explore, $modify));

        if (empty($exploreOnly) && empty($modify) && empty($ignore)) {
            return '';
        }

        $lines = ['ALLOWED FILE MAP (Token-Saving Boundaries — Inspect ONLY Explore/Modify paths; NEVER scan or load Ignore paths):'];
        $lines[] = '- Explore Only (Read): ' . (! empty($exploreOnly) ? implode(', ', $exploreOnly) : '(none)');
        $lines[] = '- Can Modify (Read & Write): ' . (! empty($modify) ? implode(', ', $modify) : '(none — read-only)');
        if (! empty($ignore)) {
            $lines[] = '- Ignore (Never Load): ' . implode(', ', $ignore);
        }

        return implode("\n", $lines);
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
