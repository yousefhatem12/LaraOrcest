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
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $agents = ['*'],
        public readonly array $allowedTools = [],
        public readonly string $instructions = '',
        public readonly array $tags = [],
        public readonly array $metadata = []
    ) {
        if (trim($this->name) === '') {
            throw new InvalidArgumentException('Skill name cannot be empty.');
        }
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
            metadata: $extraMeta
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
        return new self(
            name: (string) ($data['name'] ?? 'unnamed-skill'),
            description: (string) ($data['description'] ?? ''),
            agents: (array) ($data['agents'] ?? ['*']),
            allowedTools: (array) ($data['allowed_tools'] ?? $data['allowedTools'] ?? []),
            instructions: (string) ($data['instructions'] ?? ''),
            tags: (array) ($data['tags'] ?? []),
            metadata: (array) ($data['metadata'] ?? [])
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
