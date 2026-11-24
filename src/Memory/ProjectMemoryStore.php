<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Memory;

use Yousef\LaraOrcest\Contracts\ProjectMemoryContract;
use Yousef\LaraOrcest\State\RunState;
use Yousef\LaraOrcest\Support\Config;
use Yousef\LaraOrcest\Tools\Support\PathGuard;
use Yousef\LaraOrcest\Workspace\WorkspaceContext;

class ProjectMemoryStore implements ProjectMemoryContract
{
    protected string $filePath;

    protected bool $enabled;

    protected int $maxContextChars;

    protected int $maxItems;

    protected bool $autoIndex;

    /**
     * @var array<string, mixed>|null
     */
    protected ?array $data = null;

    protected PathGuard $pathGuard;

    protected ?WorkspaceContext $workspaceContext = null;

    public function __construct(
        ?string $path = null,
        ?PathGuard $pathGuard = null,
        ?bool $enabled = null,
        ?int $maxContextChars = null,
        ?int $maxItems = null,
        ?bool $autoIndex = null,
        ?WorkspaceContext $workspaceContext = null
    ) {
        $this->workspaceContext = $workspaceContext;
        $this->pathGuard = $pathGuard ?: ($workspaceContext ? $workspaceContext->createPathGuard() : new PathGuard());

        $configuredEnabled = $workspaceContext?->getConfig()->get('memory.enabled')
            ?: Config::get('lara-orcest.memory.enabled');
        $this->enabled = $enabled ?? ($configuredEnabled !== null ? (bool) $configuredEnabled : true);

        $configuredPath = $workspaceContext?->getConfig()->get('memory.path')
            ?: Config::get('lara-orcest.memory.path');
        $rawPath = $path ?? ($configuredPath ? (string) $configuredPath : ($workspaceContext ? $workspaceContext->getMemoryPath() : '.lara-orcest/memory.json'));
        $this->filePath = $this->resolveSafeFilePath($rawPath);

        $configuredMaxChars = $workspaceContext?->getConfig()->get('memory.max_context_chars')
            ?: Config::get('lara-orcest.memory.max_context_chars');
        $this->maxContextChars = $maxContextChars ?? ($configuredMaxChars ? (int) $configuredMaxChars : 2500);

        $configuredMaxItems = $workspaceContext?->getConfig()->get('memory.max_items')
            ?: Config::get('lara-orcest.memory.max_items');
        $this->maxItems = $maxItems ?? ($configuredMaxItems ? (int) $configuredMaxItems : 15);

        $configuredAutoIndex = $workspaceContext?->getConfig()->get('memory.auto_index')
            ?: Config::get('lara-orcest.memory.auto_index');
        $this->autoIndex = $autoIndex ?? ($configuredAutoIndex !== null ? (bool) $configuredAutoIndex : true);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    /**
     * Resolves and bounds memory file path within project root.
     */
    protected function resolveSafeFilePath(string $path): string
    {
        $root = $this->pathGuard->getProjectRoot();
        if (str_starts_with($path, '/') || (strlen($path) > 2 && $path[1] === ':')) {
            // Absolute path
            if (! str_starts_with($path, $root)) {
                $path = ltrim($path, '/');
                return $root . DIRECTORY_SEPARATOR . $path;
            }
            return $path;
        }

        return $root . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
    }

    /**
     * Loads the raw memory data array.
     *
     * @return array<string, mixed>
     */
    public function load(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        if (file_exists($this->filePath)) {
            $raw = (string) @file_get_contents($this->filePath);
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $this->data = $this->normalizeStructure($decoded);
                return $this->data;
            }
        }

        // Initialize default memory structure
        $this->data = $this->defaultStructure();

        // If auto_index is enabled, scan codebase to pre-populate index
        if ($this->autoIndex && $this->enabled) {
            $this->scanCodebase();
        }

        return $this->data;
    }

    /**
     * Returns default memory data structure.
     *
     * @return array<string, mixed>
     */
    protected function defaultStructure(): array
    {
        return [
            'schema_version' => 1,
            'codebase_index' => [
                'directories' => [
                    'app/Models',
                    'app/Http/Controllers',
                    'routes',
                    'database/migrations',
                    'tests/Feature',
                    'tests/Unit',
                ],
                'models' => [],
                'controllers' => [],
                'migrations' => [],
                'routes' => [],
            ],
            'conventions' => [
                [
                    'category' => 'general',
                    'rule' => 'Strict types: declare(strict_types=1) at the top of all PHP files.',
                ],
                [
                    'category' => 'backend',
                    'rule' => 'Validation: Always use dedicated FormRequest classes for HTTP endpoint validation.',
                ],
                [
                    'category' => 'backend',
                    'rule' => 'Thin controllers: Keep controllers thin and delegate domain logic to Action or Service classes.',
                ],
                [
                    'category' => 'database',
                    'rule' => 'Eloquent: Use relationships and explicit casts; avoid raw SQL queries.',
                ],
                [
                    'category' => 'testing',
                    'rule' => 'Automated tests: Provide Feature tests under tests/Feature for all route endpoints.',
                ],
            ],
            'decisions' => [],
            'pitfalls' => [],
            'metadata' => [
                'updated_at' => null,
                'consolidations_count' => 0,
            ],
        ];
    }

    /**
     * Normalizes loaded structure to ensure all required keys exist.
     *
     * @param  array<string, mixed>  $decoded
     * @return array<string, mixed>
     */
    protected function normalizeStructure(array $decoded): array
    {
        $default = $this->defaultStructure();

        $decoded['schema_version'] = (int) ($decoded['schema_version'] ?? 1);
        $decoded['codebase_index'] = array_merge($default['codebase_index'], (array) ($decoded['codebase_index'] ?? []));
        $decoded['conventions'] = (array) ($decoded['conventions'] ?? $default['conventions']);
        $decoded['decisions'] = (array) ($decoded['decisions'] ?? []);
        $decoded['pitfalls'] = (array) ($decoded['pitfalls'] ?? []);
        $decoded['metadata'] = array_merge($default['metadata'], (array) ($decoded['metadata'] ?? []));

        return $decoded;
    }

    /**
     * Persists memory data array to disk.
     *
     * @param  array<string, mixed>  $data
     */
    public function save(array $data): void
    {
        $dir = dirname($this->filePath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // Ensure .gitignore exists in .lara-orcest directory
        $gitIgnorePath = $dir . DIRECTORY_SEPARATOR . '.gitignore';
        if (! file_exists($gitIgnorePath) && basename($dir) === '.lara-orcest') {
            @file_put_contents($gitIgnorePath, "# Ignore local run memory and state by default\n*.json\n");
        }

        $data['metadata']['updated_at'] = date('c');
        $this->data = $data;

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        @file_put_contents($this->filePath, $json, LOCK_EX);
    }

    public function getIndex(): array
    {
        $data = $this->load();

        return (array) ($data['codebase_index'] ?? []);
    }

    public function getConventions(): array
    {
        $data = $this->load();

        return (array) ($data['conventions'] ?? []);
    }

    public function getDecisions(): array
    {
        $data = $this->load();

        return (array) ($data['decisions'] ?? []);
    }

    public function getPitfalls(): array
    {
        $data = $this->load();

        return (array) ($data['pitfalls'] ?? []);
    }

    /**
     * Scans the project directory structure to populate or refresh codebase_index.
     */
    public function scanCodebase(?string $rootPath = null): self
    {
        $root = $rootPath ?: $this->pathGuard->getProjectRoot();
        $data = $this->load();

        $index = $data['codebase_index'] ?? [];

        // 1. Scan app/Models
        $modelsDir = $root . '/app/Models';
        if (is_dir($modelsDir)) {
            $files = @scandir($modelsDir) ?: [];
            foreach ($files as $file) {
                if (str_ends_with($file, '.php')) {
                    $className = substr($file, 0, -4);
                    $index['models'][$className] = "app/Models/{$file}";
                }
            }
        }

        // 2. Scan app/Http/Controllers
        $controllersDir = $root . '/app/Http/Controllers';
        if (is_dir($controllersDir)) {
            $files = @scandir($controllersDir) ?: [];
            foreach ($files as $file) {
                if (str_ends_with($file, '.php')) {
                    $className = substr($file, 0, -4);
                    $index['controllers'][$className] = "app/Http/Controllers/{$file}";
                }
            }
        }

        // 3. Scan database/migrations
        $migrationsDir = $root . '/database/migrations';
        if (is_dir($migrationsDir)) {
            $files = @scandir($migrationsDir) ?: [];
            foreach ($files as $file) {
                if (str_ends_with($file, '.php')) {
                    $index['migrations'][$file] = "database/migrations/{$file}";
                }
            }
        }

        // 4. Scan routes
        $routesDir = $root . '/routes';
        if (is_dir($routesDir)) {
            $files = @scandir($routesDir) ?: [];
            foreach ($files as $file) {
                if (str_ends_with($file, '.php')) {
                    $routePath = "routes/{$file}";
                    if (! in_array($routePath, $index['routes'] ?? [], true)) {
                        $index['routes'][] = $routePath;
                    }
                }
            }
        }

        $data['codebase_index'] = $index;
        $this->save($data);

        return $this;
    }

    /**
     * Recalls a compact, agent- and task-scoped slice of project memory.
     *
     * @param  array<string, mixed>  $options
     */
    public function recallForContext(string $agentKey, string $prompt, array $options = []): string
    {
        $overrideEnabled = $options['memory_enabled'] ?? null;
        if ($overrideEnabled === false || (! $this->enabled && $overrideEnabled !== true)) {
            return '';
        }

        $data = $this->load();
        $maxChars = (int) ($options['max_context_chars'] ?? $options['max_chars'] ?? $this->maxContextChars);
        $maxItems = (int) ($options['max_items'] ?? $this->maxItems);

        $keywords = $this->extractKeywords($prompt);

        // 1. Filter conventions relevant to this agent
        $relevantConventions = $this->filterConventions($data['conventions'] ?? [], $agentKey, $keywords, $maxItems);

        // 2. Select relevant decisions
        $relevantDecisions = $this->selectDecisions($data['decisions'] ?? [], $keywords, $maxItems);

        // 3. Select relevant pitfalls
        $relevantPitfalls = $this->selectPitfalls($data['pitfalls'] ?? [], $agentKey, $keywords, $maxItems);

        // 4. Select relevant codebase index summary
        $codebaseSlice = $this->formatCodebaseSlice($data['codebase_index'] ?? [], $agentKey, $keywords);

        // 5. Compose formatted markdown block
        return $this->formatMarkdownContext(
            agentKey: $agentKey,
            conventions: $relevantConventions,
            codebaseSlice: $codebaseSlice,
            decisions: $relevantDecisions,
            pitfalls: $relevantPitfalls,
            maxChars: $maxChars
        );
    }

    /**
     * Consolidates completed run state into the persistent project memory store.
     */
    public function consolidate(RunState $state): void
    {
        $memoryEnabled = $state->isMemoryEnabled();
        if (! $memoryEnabled || ! $this->enabled || $state->isDryRun()) {
            return;
        }

        $data = $this->load();

        // 1. Update codebase_index with newly created or modified artifacts
        $index = $data['codebase_index'] ?? [];
        foreach ($state->artifacts as $artifact) {
            $path = (string) ($artifact['path'] ?? '');
            if ($path === '') {
                continue;
            }

            // Track directory
            $dir = dirname($path);
            if ($dir !== '.' && ! in_array($dir, $index['directories'] ?? [], true)) {
                $index['directories'][] = $dir;
            }

            if (str_starts_with($path, 'app/Models/') && str_ends_with($path, '.php')) {
                $modelName = basename($path, '.php');
                $index['models'][$modelName] = $path;
            } elseif (str_starts_with($path, 'app/Http/Controllers/') && str_ends_with($path, '.php')) {
                $controllerName = basename($path, '.php');
                $index['controllers'][$controllerName] = $path;
            } elseif (str_starts_with($path, 'database/migrations/') && str_ends_with($path, '.php')) {
                $migrationName = basename($path, '.php');
                $index['migrations'][$migrationName] = $path;
            } elseif (str_starts_with($path, 'routes/')) {
                if (! in_array($path, $index['routes'] ?? [], true)) {
                    $index['routes'][] = $path;
                }
            }
        }
        $data['codebase_index'] = $index;

        // 2. Record Technical Decision if run has prompt/plan
        $this->consolidateDecision($data, $state);

        // 3. Record Resolved Pitfalls (from fixer resolutions or resolved test failures)
        $this->consolidatePitfalls($data, $state);

        // 4. Update metadata
        $data['metadata']['consolidations_count'] = (int) ($data['metadata']['consolidations_count'] ?? 0) + 1;
        $data['metadata']['updated_at'] = date('c');

        // 5. Enforce max_items bounds on history
        if (count($data['decisions']) > $this->maxItems) {
            $data['decisions'] = array_slice($data['decisions'], -$this->maxItems);
        }
        if (count($data['pitfalls']) > $this->maxItems) {
            $data['pitfalls'] = array_slice($data['pitfalls'], -$this->maxItems);
        }

        // 6. Save atomically
        $this->save($data);
    }

    /**
     * Consolidates a run's architectural/technical decision.
     *
     * @param  array<string, mixed>  $data
     */
    protected function consolidateDecision(array &$data, RunState $state): void
    {
        if (trim($state->prompt) === '') {
            return;
        }

        $title = mb_substr(trim($state->prompt), 0, 80);
        $artifactPaths = array_column($state->artifacts, 'path');

        $summary = "Implemented feature: {$title}.";
        if (! empty($state->technicalPlan['execution_steps']) && is_array($state->technicalPlan['execution_steps'])) {
            $steps = array_map(function ($s) {
                return is_string($s) ? $s : ($s['description'] ?? json_encode($s));
            }, array_slice($state->technicalPlan['execution_steps'], 0, 3));
            $summary .= ' Key steps: ' . implode('; ', $steps);
        }

        // Deduplicate against existing decisions for same run_id or identical title
        $existingDecisions = (array) ($data['decisions'] ?? []);
        foreach ($existingDecisions as $idx => $existing) {
            if (($existing['run_id'] ?? '') === $state->runId || ($existing['title'] ?? '') === $title) {
                // Update existing decision rather than creating duplicate
                $existingDecisions[$idx]['summary'] = $summary;
                $existingDecisions[$idx]['files'] = array_values(array_unique(array_merge($existing['files'] ?? [], $artifactPaths)));
                $existingDecisions[$idx]['timestamp'] = date('c');
                $data['decisions'] = $existingDecisions;
                return;
            }
        }

        $decisionId = 'dec_' . substr(md5($state->runId . $title), 0, 8);
        $data['decisions'][] = [
            'id' => $decisionId,
            'run_id' => $state->runId,
            'title' => $title,
            'summary' => $this->sanitizeText($summary),
            'files' => array_values(array_unique($artifactPaths)),
            'timestamp' => date('c'),
        ];
    }

    /**
     * Consolidates resolved pitfalls from fixer agent or test failures.
     *
     * @param  array<string, mixed>  $data
     */
    protected function consolidatePitfalls(array &$data, RunState $state): void
    {
        $fixAttempts = (int) ($state->guardrails['fix_attempts'] ?? 0);
        $resolutions = (array) ($state->metadata['fixer_resolutions'] ?? []);

        // Record explicit fixer resolutions
        foreach ($resolutions as $res) {
            $rootCause = trim((string) ($res['root_cause'] ?? ''));
            if ($rootCause === '') {
                continue;
            }

            $fixedFiles = (array) ($res['fixed_files'] ?? []);
            $resolutionSummary = ! empty($fixedFiles)
                ? 'Applied fix to files: ' . implode(', ', $fixedFiles)
                : 'Applied targeted code corrections.';

            $issue = 'Test or validation failure during execution';
            $this->appendPitfall($data, $issue, $rootCause, $resolutionSummary, $fixedFiles);
        }

        // If fix attempts were made but no structured resolution was logged
        if ($fixAttempts > 0 && empty($resolutions) && $state->areTestsPassing()) {
            $issue = 'Test failures resolved after ' . $fixAttempts . ' fix attempt(s)';
            $rootCause = 'Initial implementation failed automated assertions.';
            $resolution = 'FixerAgent corrected test failures and verified passing test suite.';
            $this->appendPitfall($data, $issue, $rootCause, $resolution, array_column($state->artifacts, 'path'));
        }
    }

    /**
     * Appends a pitfall record with deduplication.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $paths
     */
    protected function appendPitfall(array &$data, string $issue, string $rootCause, string $resolution, array $paths = []): void
    {
        $existingPitfalls = (array) ($data['pitfalls'] ?? []);

        // Deduplicate: Check if a pitfall with similar root cause already exists
        foreach ($existingPitfalls as $idx => $existing) {
            if (levenshtein(substr($existing['root_cause'] ?? '', 0, 100), substr($rootCause, 0, 100)) < 15) {
                // Update existing pitfall
                $existingPitfalls[$idx]['resolution'] = $this->sanitizeText($resolution);
                $existingPitfalls[$idx]['timestamp'] = date('c');
                $data['pitfalls'] = $existingPitfalls;
                return;
            }
        }

        $pitfallId = 'pit_' . substr(md5($rootCause), 0, 8);
        $data['pitfalls'][] = [
            'id' => $pitfallId,
            'issue' => $this->sanitizeText($issue),
            'root_cause' => $this->sanitizeText($rootCause),
            'resolution' => $this->sanitizeText($resolution),
            'paths' => array_values(array_unique($paths)),
            'timestamp' => date('c'),
        ];
    }

    /**
     * Manually records an architectural or technical decision.
     *
     * @param  array<string, mixed>  $context
     */
    public function recordDecision(string $title, string $summary, array $context = []): self
    {
        $data = $this->load();
        $decisionId = 'dec_' . substr(md5($title . date('c')), 0, 8);

        $data['decisions'][] = [
            'id' => $decisionId,
            'run_id' => (string) ($context['run_id'] ?? 'manual'),
            'title' => $this->sanitizeText($title),
            'summary' => $this->sanitizeText($summary),
            'files' => (array) ($context['files'] ?? []),
            'timestamp' => date('c'),
        ];

        if (count($data['decisions']) > $this->maxItems) {
            $data['decisions'] = array_slice($data['decisions'], -$this->maxItems);
        }

        $this->save($data);

        return $this;
    }

    /**
     * Manually records a resolved pitfall.
     *
     * @param  array<string, mixed>  $context
     */
    public function recordPitfall(string $issue, string $rootCause, string $resolution, array $context = []): self
    {
        $data = $this->load();
        $this->appendPitfall($data, $issue, $rootCause, $resolution, (array) ($context['paths'] ?? []));
        $this->save($data);

        return $this;
    }

    /**
     * Manually records a project convention.
     */
    public function recordConvention(string $rule, string $category = 'general'): self
    {
        $data = $this->load();
        $conventions = (array) ($data['conventions'] ?? []);

        // Deduplicate
        foreach ($conventions as $conv) {
            $existingRule = is_array($conv) ? ($conv['rule'] ?? '') : (string) $conv;
            if ($existingRule === $rule) {
                return $this;
            }
        }

        $conventions[] = [
            'category' => $category,
            'rule' => $this->sanitizeText($rule),
        ];

        $data['conventions'] = $conventions;
        $this->save($data);

        return $this;
    }

    /**
     * Clears all memory entries and resets to default structure.
     */
    public function clear(): void
    {
        $this->data = $this->defaultStructure();
        $this->save($this->data);
    }

    /**
     * Extracts search keywords from prompt, stripping common stop words.
     *
     * @return array<int, string>
     */
    protected function extractKeywords(string $prompt): array
    {
        $stopWords = [
            'the', 'a', 'an', 'and', 'or', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by',
            'is', 'it', 'as', 'be', 'do', 'that', 'this', 'from', 'create', 'make', 'add',
            'implement', 'build', 'using', 'should', 'have', 'all', 'any', 'new', 'feature',
        ];

        $cleaned = preg_replace('/[^a-zA-Z0-9_\-\s]/', ' ', strtolower($prompt));
        $words = preg_split('/\s+/', (string) $cleaned, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $keywords = [];
        foreach ($words as $word) {
            if (strlen($word) >= 3 && ! in_array($word, $stopWords, true)) {
                $keywords[] = $word;
            }
        }

        return array_values(array_unique($keywords));
    }

    /**
     * Filters conventions based on agent role and relevance.
     *
     * @param  array<int, mixed>  $conventions
     * @param  array<int, string>  $keywords
     * @return array<int, string>
     */
    protected function filterConventions(array $conventions, string $agentKey, array $keywords, int $limit): array
    {
        $roleCategories = match ($agentKey) {
            'architect' => ['general', 'architecture', 'database', 'backend'],
            'database_agent' => ['general', 'database'],
            'backend_agent' => ['general', 'backend', 'architecture'],
            'swagger_agent' => ['general', 'backend', 'documentation', 'architecture'],
            'qa_agent' => ['general', 'testing'],
            'fixer_agent' => ['general', 'testing', 'backend'],
            'reviewer_agent' => ['general', 'backend', 'database', 'testing', 'architecture'],
            default => ['general', 'architecture'],
        };

        $matched = [];
        foreach ($conventions as $conv) {
            $rule = is_array($conv) ? (string) ($conv['rule'] ?? '') : (string) $conv;
            $cat = is_array($conv) ? (string) ($conv['category'] ?? 'general') : 'general';

            if ($rule === '') {
                continue;
            }

            // Category match
            if (in_array($cat, $roleCategories, true)) {
                $matched[] = $rule;
                continue;
            }

            // Keyword match
            foreach ($keywords as $kw) {
                if (stripos($rule, $kw) !== false) {
                    $matched[] = $rule;
                    break;
                }
            }
        }

        return array_slice(array_unique($matched), 0, min($limit, 5));
    }

    /**
     * Selects decisions relevant to prompt keywords.
     *
     * @param  array<int, array<string, mixed>>  $decisions
     * @param  array<int, string>  $keywords
     * @return array<int, array<string, mixed>>
     */
    protected function selectDecisions(array $decisions, array $keywords, int $limit): array
    {
        if (empty($decisions)) {
            return [];
        }

        $scored = [];
        foreach ($decisions as $dec) {
            $score = 0;
            $haystack = strtolower(($dec['title'] ?? '') . ' ' . ($dec['summary'] ?? '') . ' ' . implode(' ', $dec['files'] ?? []));
            foreach ($keywords as $kw) {
                if (str_contains($haystack, $kw)) {
                    $score += 2;
                }
            }

            $scored[] = [
                'decision' => $dec,
                'score' => $score,
            ];
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        $result = [];
        foreach (array_slice($scored, 0, min($limit, 3)) as $item) {
            $result[] = $item['decision'];
        }

        return $result;
    }

    /**
     * Selects pitfalls relevant to agent role and prompt keywords.
     *
     * @param  array<int, array<string, mixed>>  $pitfalls
     * @param  array<int, string>  $keywords
     * @return array<int, array<string, mixed>>
     */
    protected function selectPitfalls(array $pitfalls, string $agentKey, array $keywords, int $limit): array
    {
        if (empty($pitfalls)) {
            return [];
        }

        $scored = [];
        foreach ($pitfalls as $pit) {
            $score = ($agentKey === 'fixer_agent') ? 3 : 0;
            $haystack = strtolower(($pit['issue'] ?? '') . ' ' . ($pit['root_cause'] ?? '') . ' ' . ($pit['resolution'] ?? '') . ' ' . implode(' ', $pit['paths'] ?? []));
            foreach ($keywords as $kw) {
                if (str_contains($haystack, $kw)) {
                    $score += 2;
                }
            }

            $scored[] = [
                'pitfall' => $pit,
                'score' => $score,
            ];
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        $result = [];
        foreach (array_slice($scored, 0, min($limit, 3)) as $item) {
            $result[] = $item['pitfall'];
        }

        return $result;
    }

    /**
     * Formats a lightweight, role-tailored slice of the codebase index.
     *
     * @param  array<string, mixed>  $index
     * @param  array<int, string>  $keywords
     */
    protected function formatCodebaseSlice(array $index, string $agentKey, array $keywords): string
    {
        $lines = [];

        // Models
        $models = (array) ($index['models'] ?? []);
        if (! empty($models)) {
            $matchedModels = [];
            foreach ($models as $name => $path) {
                $isMatch = false;
                foreach ($keywords as $kw) {
                    if (stripos((string) $name, $kw) !== false) {
                        $isMatch = true;
                        break;
                    }
                }
                if ($isMatch || count($matchedModels) < 4) {
                    $matchedModels[] = "{$name} ({$path})";
                }
            }
            if (! empty($matchedModels)) {
                $lines[] = '- Models: ' . implode(', ', array_slice($matchedModels, 0, 5));
            }
        }

        // Controllers
        $controllers = (array) ($index['controllers'] ?? []);
        if (! empty($controllers) && in_array($agentKey, ['architect', 'backend_agent', 'reviewer_agent', 'swagger_agent'], true)) {
            $matchedControllers = [];
            foreach ($controllers as $name => $path) {
                $matchedControllers[] = "{$name} ({$path})";
            }
            if (! empty($matchedControllers)) {
                $lines[] = '- Controllers: ' . implode(', ', array_slice($matchedControllers, 0, 4));
            }
        }

        // Routes
        $routes = (array) ($index['routes'] ?? []);
        if (! empty($routes) && in_array($agentKey, ['architect', 'backend_agent', 'qa_agent', 'swagger_agent'], true)) {
            $lines[] = '- Routes: ' . implode(', ', array_slice($routes, 0, 4));
        }

        // Migrations
        $migrations = (array) ($index['migrations'] ?? []);
        if (! empty($migrations) && in_array($agentKey, ['architect', 'database_agent'], true)) {
            $lines[] = '- Migrations: ' . count($migrations) . ' registered migrations';
        }

        return implode("\n", $lines);
    }

    /**
     * Formats the final compact markdown block, strictly respecting maxChars.
     *
     * @param  array<int, string>  $conventions
     * @param  array<int, array<string, mixed>>  $decisions
     * @param  array<int, array<string, mixed>>  $pitfalls
     */
    protected function formatMarkdownContext(
        string $agentKey,
        array $conventions,
        string $codebaseSlice,
        array $decisions,
        array $pitfalls,
        int $maxChars
    ): string {
        $sections = [];

        $sections[] = "### PROJECT MEMORY & CODEBASE CONTEXT (Compact Overview)";
        $sections[] = "> Note: High-level distilled index. Use FileReaderTool to inspect exact file lines and contents as needed.";

        $pitfallsBlock = null;
        if (! empty($pitfalls)) {
            $pitLines = [];
            foreach ($pitfalls as $pit) {
                $cause = $pit['root_cause'] ?? 'Issue encountered';
                $res = $pit['resolution'] ?? 'Resolved';
                $pitLines[] = "- [Pitfall]: {$cause} -> Fix: {$res}";
            }
            $pitfallsBlock = "**Known Pitfalls & Resolutions:**\n" . implode("\n", $pitLines);
        }

        $conventionsBlock = null;
        if (! empty($conventions)) {
            $conventionsBlock = "**Conventions:**\n" . implode("\n", array_map(fn ($c) => "- {$c}", $conventions));
        }

        $codebaseBlock = null;
        if (trim($codebaseSlice) !== '') {
            $codebaseBlock = "**Codebase Structure:**\n" . $codebaseSlice;
        }

        $decisionsBlock = null;
        if (! empty($decisions)) {
            $decLines = [];
            foreach ($decisions as $dec) {
                $title = $dec['title'] ?? 'Milestone';
                $summary = $dec['summary'] ?? '';
                $decLines[] = "- [{$title}]: {$summary}";
            }
            $decisionsBlock = "**Relevant Past Decisions:**\n" . implode("\n", $decLines);
        }

        // Role-based section prioritization
        if ($agentKey === 'fixer_agent') {
            if ($pitfallsBlock !== null) {
                $sections[] = $pitfallsBlock;
            }
            if ($conventionsBlock !== null) {
                $sections[] = $conventionsBlock;
            }
            if ($decisionsBlock !== null) {
                $sections[] = $decisionsBlock;
            }
            if ($codebaseBlock !== null) {
                $sections[] = $codebaseBlock;
            }
        } elseif ($agentKey === 'architect') {
            if ($codebaseBlock !== null) {
                $sections[] = $codebaseBlock;
            }
            if ($conventionsBlock !== null) {
                $sections[] = $conventionsBlock;
            }
            if ($decisionsBlock !== null) {
                $sections[] = $decisionsBlock;
            }
            if ($pitfallsBlock !== null) {
                $sections[] = $pitfallsBlock;
            }
        } else {
            if ($conventionsBlock !== null) {
                $sections[] = $conventionsBlock;
            }
            if ($codebaseBlock !== null) {
                $sections[] = $codebaseBlock;
            }
            if ($decisionsBlock !== null) {
                $sections[] = $decisionsBlock;
            }
            if ($pitfallsBlock !== null) {
                $sections[] = $pitfallsBlock;
            }
        }

        $full = implode("\n\n", $sections);

        if (mb_strlen($full) <= $maxChars) {
            return $full;
        }

        // Progressively truncate if exceeding maxChars
        $notice = "\n[... Project memory truncated to fit context budget]";
        $targetLength = max(100, $maxChars - mb_strlen($notice));

        return mb_substr($full, 0, $targetLength) . $notice;
    }

    /**
     * Sanitizes strings to prevent secret leakage in stored memory.
     */
    protected function sanitizeText(string $text): string
    {
        // Redact potential API keys or token patterns
        $text = preg_replace('/(sk-[a-zA-Z0-9]{20,})/i', '[REDACTED_API_KEY]', $text) ?? $text;
        $text = preg_replace('/(bearer\s+[a-zA-Z0-9\-\._~\+\/]+=*)/i', 'Bearer [REDACTED_TOKEN]', $text) ?? $text;
        $text = preg_replace('/(api[_-]?key["\']?\s*[:=]\s*["\']?)([^"\'\s]+)/i', '$1[REDACTED]', $text) ?? $text;

        return trim($text);
    }
}
