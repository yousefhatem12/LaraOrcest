<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Skills;

use InvalidArgumentException;

class Skill
{
    /**
     * @param  array<int, string>  $agents
     * @param  array<int, string>  $allowedTools
     * @param  array<int, string>  $tags
     * @param  array<string, mixed>  $metadata
     * @param  array<int, string>  $explorePaths
     * @param  array<int, string>  $modifyPaths
     * @param  array<int, string>  $ignorePaths
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $agents = ['*'],
        public readonly array $allowedTools = [],
        public readonly string $instructions = '',
        public readonly array $tags = [],
        public readonly array $metadata = [],
        public readonly array $explorePaths = [],
        public readonly array $modifyPaths = [],
        public readonly array $ignorePaths = [],
        public readonly bool $hasExplicitExplorePaths = false,
        public readonly bool $hasExplicitModifyPaths = false
    ) {
        if (trim($this->name) === '') {
            throw new InvalidArgumentException('Skill name cannot be empty.');
        }
    }

    /**
     * Narrow globs this skill allows the agent to read/explore for context.
     *
     * @return array<int, string>
     */
    public function explorePaths(): array
    {
        return $this->explorePaths;
    }

    /**
     * Exact globs this skill allows the agent to create or modify.
     *
     * @return array<int, string>
     */
    public function modifyPaths(): array
    {
        return $this->modifyPaths;
    }

    /**
     * Excluded paths/globs that must never be loaded into prompt context.
     *
     * @return array<int, string>
     */
    public function ignorePaths(): array
    {
        return $this->ignorePaths;
    }

    public function hasDeclaredExplorePaths(): bool
    {
        return $this->hasExplicitExplorePaths || ! empty($this->explorePaths) || ! empty($this->modifyPaths);
    }

    public function hasDeclaredModifyPaths(): bool
    {
        return $this->hasExplicitModifyPaths || ! empty($this->modifyPaths);
    }

    /**
     * Check if this skill is applicable to a specific agent key or parent agent key.
     */
    public function appliesToAgent(string $agentKey, ?string $parentAgentKey = null): bool
    {
        if (empty($this->agents) || in_array('*', $this->agents, true)) {
            return true;
        }

        $needle = strtolower(trim($agentKey));
        $parentNeedle = $parentAgentKey !== null ? strtolower(trim($parentAgentKey)) : null;

        foreach ($this->agents as $candidate) {
            $cand = strtolower(trim($candidate));
            if ($cand === $needle || ($parentNeedle !== null && $cand === $parentNeedle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if this skill permits or requires a specific tool.
     */
    public function permitsTool(string $toolName): bool
    {
        if (empty($this->allowedTools) || in_array('*', $this->allowedTools, true)) {
            return true;
        }

        return in_array($toolName, $this->allowedTools, true);
    }

    /**
     * Parse a markdown file with optional YAML-like frontmatter into a Skill instance.
     *
     * @param  array<string, mixed>  $extraMeta
     */
    public static function parseFromMarkdown(string $content, ?string $fallbackName = null, array $extraMeta = []): self
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $content);

        $name = $fallbackName ?? 'unnamed-skill';
        $description = '';
        $agents = ['*'];
        $allowedTools = [];
        $tags = [];
        $explorePaths = [];
        $modifyPaths = [];
        $ignorePaths = [];
        $hasExplicitExplorePaths = false;
        $hasExplicitModifyPaths = false;
        $instructions = trim($normalized);

        // Check for YAML-like frontmatter: ^---\n(.*?)\n---\n(.*)$
        if (preg_match('/^---\s*\n(.*?)\n---\s*(?:\n|$)(.*)$/s', $normalized, $matches)) {
            $frontMatterRaw = $matches[1];
            $instructions = trim($matches[2]);

            $parsed = self::parseFrontMatter($frontMatterRaw);

            if (! empty($parsed['name'])) {
                $name = (string) $parsed['name'];
            }
            if (! empty($parsed['description'])) {
                $description = (string) $parsed['description'];
            }
            if (isset($parsed['agents'])) {
                $agents = self::normalizeList($parsed['agents']);
            }
            if (isset($parsed['allowed_tools'])) {
                $allowedTools = self::normalizeList($parsed['allowed_tools']);
            } elseif (isset($parsed['allowedTools'])) {
                $allowedTools = self::normalizeList($parsed['allowedTools']);
            }
            if (isset($parsed['tags'])) {
                $tags = self::normalizeList($parsed['tags']);
            }
            if (array_key_exists('explore_paths', $parsed)) {
                $explorePaths = self::normalizeList($parsed['explore_paths']);
                $hasExplicitExplorePaths = true;
            } elseif (array_key_exists('explorepaths', $parsed)) {
                $explorePaths = self::normalizeList($parsed['explorepaths']);
                $hasExplicitExplorePaths = true;
            }
            if (array_key_exists('modify_paths', $parsed)) {
                $modifyPaths = self::normalizeList($parsed['modify_paths']);
                $hasExplicitModifyPaths = true;
            } elseif (array_key_exists('modifypaths', $parsed)) {
                $modifyPaths = self::normalizeList($parsed['modifypaths']);
                $hasExplicitModifyPaths = true;
            }
            if (array_key_exists('ignore_paths', $parsed)) {
                $ignorePaths = self::normalizeList($parsed['ignore_paths']);
            } elseif (array_key_exists('ignorepaths', $parsed)) {
                $ignorePaths = self::normalizeList($parsed['ignorepaths']);
            }
        } else {
            // Fallback: extract first # Heading as title and first non-heading line as description
            if (preg_match('/^#\s+(.+)$/m', $normalized, $headingMatch)) {
                $headingTitle = trim($headingMatch[1]);
                if ($fallbackName === null) {
                    $name = strtolower(preg_replace('/[^a-zA-Z0-9_-]+/', '-', $headingTitle) ?? 'skill');
                }
                if ($description === '') {
                    $description = $headingTitle;
                }
            }
        }

        if ($description === '') {
            $description = "Skill instructions for {$name}";
        }

        return new self(
            name: $name,
            description: $description,
            agents: $agents,
            allowedTools: $allowedTools,
            instructions: $instructions,
            tags: $tags,
            metadata: $extraMeta,
            explorePaths: $explorePaths,
            modifyPaths: $modifyPaths,
            ignorePaths: $ignorePaths,
            hasExplicitExplorePaths: $hasExplicitExplorePaths,
            hasExplicitModifyPaths: $hasExplicitModifyPaths
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'agents' => $this->agents,
            'allowed_tools' => $this->allowedTools,
            'explore_paths' => $this->explorePaths,
            'modify_paths' => $this->modifyPaths,
            'ignore_paths' => $this->ignorePaths,
            'instructions' => $this->instructions,
            'tags' => $this->tags,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $hasExplore = array_key_exists('explore_paths', $data) || array_key_exists('explorePaths', $data);
        $hasModify = array_key_exists('modify_paths', $data) || array_key_exists('modifyPaths', $data);

        return new self(
            name: (string) ($data['name'] ?? 'unnamed-skill'),
            description: (string) ($data['description'] ?? ''),
            agents: (array) ($data['agents'] ?? ['*']),
            allowedTools: (array) ($data['allowed_tools'] ?? $data['allowedTools'] ?? []),
            instructions: (string) ($data['instructions'] ?? ''),
            tags: (array) ($data['tags'] ?? []),
            metadata: (array) ($data['metadata'] ?? []),
            explorePaths: self::normalizeList($data['explore_paths'] ?? $data['explorePaths'] ?? []),
            modifyPaths: self::normalizeList($data['modify_paths'] ?? $data['modifyPaths'] ?? []),
            ignorePaths: self::normalizeList($data['ignore_paths'] ?? $data['ignorePaths'] ?? []),
            hasExplicitExplorePaths: $hasExplore,
            hasExplicitModifyPaths: $hasModify
        );
    }

    /**
     * Parse key-value frontmatter lines.
     *
     * @return array<string, mixed>
     */
    protected static function parseFrontMatter(string $raw): array
    {
        $lines = explode("\n", $raw);
        $result = [];
        $currentKey = null;
        $currentList = [];
        $inList = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            // List item: - value
            if (preg_match('/^-\s+(.+)$/', $trimmed, $listMatch)) {
                $val = trim($listMatch[1], " \t\n\r\0\x0B\"'");
                if ($inList && $currentKey !== null) {
                    $currentList[] = $val;
                }
                continue;
            }

            // Flush previous list if any
            if ($inList && $currentKey !== null) {
                $result[$currentKey] = $currentList;
                $inList = false;
                $currentList = [];
                $currentKey = null;
            }

            // Key: Value
            if (preg_match('/^([a-zA-Z0-9_-]+)\s*:\s*(.*)$/', $trimmed, $kvMatch)) {
                $key = strtolower(trim($kvMatch[1]));
                $value = trim($kvMatch[2]);

                if ($value === '' || $value === '|' || $value === '>-') {
                    // Start of list or multi-line block
                    $currentKey = $key;
                    $currentList = [];
                    $inList = true;
                } else {
                    // Check if JSON array like ["a", "b"]
                    if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
                        $decoded = json_decode($value, true);
                        $result[$key] = is_array($decoded) ? $decoded : [$value];
                    } else {
                        $result[$key] = trim($value, " \t\n\r\0\x0B\"'");
                    }
                }
            }
        }

        if ($inList && $currentKey !== null) {
            $result[$currentKey] = $currentList;
        }

        return $result;
    }

    /**
     * Normalize string, comma-separated, or array to a list of strings.
     *
     * @param  mixed  $value
     * @return array<int, string>
     */
    protected static function normalizeList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('strval', $value), fn ($v) => trim($v) !== ''));
        }

        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return [];
            }
            if (str_contains($value, ',')) {
                return array_values(array_filter(array_map('trim', explode(',', $value)), fn ($v) => $v !== ''));
            }

            return [$value];
        }

        return [];
    }
}
