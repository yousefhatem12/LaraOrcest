<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Config;

use Yousef\LaraOrcest\Support\Config;

class OrchestratorConfig
{
    /**
     * @var array<string, mixed>
     */
    protected array $data = [];

    protected ?string $targetProjectPath = null;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(array $data = [], ?string $targetProjectPath = null)
    {
        $this->data = $data;
        $this->targetProjectPath = $targetProjectPath;
    }

    /**
     * Loads configuration from package defaults, target project overrides, and environment.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function load(?string $targetProjectPath = null, array $overrides = []): self
    {
        $targetProjectPath = self::resolveTargetProjectPath($targetProjectPath);

        // 1. Load environment variables from target workspace and/or current working directory if .env exists
        EnvCredentialManager::loadEnvFiles($targetProjectPath);

        // 2. Load package default configuration
        $defaultsPath = __DIR__ . '/../../config/lara-orcest.php';
        $config = file_exists($defaultsPath) ? (array) (require $defaultsPath) : [];

        // 3. Merge target project's config/lara-orcest.php if it exists
        if ($targetProjectPath !== null) {
            $laravelConfigPath = $targetProjectPath . '/config/lara-orcest.php';
            if (file_exists($laravelConfigPath)) {
                $laravelConfig = (array) (require $laravelConfigPath);
                $config = self::mergeArrays($config, $laravelConfig);
            }
        }

        // 4. Merge workspace-level config (.lara-orcest/config.php or .lara-orcest/config.json)
        if ($targetProjectPath !== null) {
            $workspacePhpConfig = $targetProjectPath . '/.lara-orcest/config.php';
            if (file_exists($workspacePhpConfig)) {
                $wsConfig = (array) (require $workspacePhpConfig);
                $config = self::mergeArrays($config, $wsConfig);
            }

            $workspaceJsonConfig = $targetProjectPath . '/.lara-orcest/config.json';
            if (file_exists($workspaceJsonConfig)) {
                $jsonContent = (string) file_get_contents($workspaceJsonConfig);
                $decoded = json_decode($jsonContent, true);
                if (is_array($decoded)) {
                    $config = self::mergeArrays($config, $decoded);
                }
            }
        }

        // 5. Merge cwd .lara-orcest/config.json if different from targetProjectPath
        $cwd = getcwd() ?: '.';
        if ($targetProjectPath === null || realpath($cwd) !== realpath($targetProjectPath)) {
            $cwdJson = $cwd . '/.lara-orcest/config.json';
            if (file_exists($cwdJson)) {
                $jsonContent = (string) file_get_contents($cwdJson);
                $decoded = json_decode($jsonContent, true);
                if (is_array($decoded)) {
                    $config = self::mergeArrays($config, $decoded);
                }
            }
        }

        // 6. Merge runtime overrides
        if (! empty($overrides)) {
            $config = self::mergeArrays($config, $overrides);
        }

        $instance = new self($config, $targetProjectPath);

        // Register with global Config bridge
        Config::setOrchestratorConfig($instance);

        return $instance;
    }

    /**
     * Resolves and canonicalizes target project path.
     */
    protected static function resolveTargetProjectPath(?string $targetPath): ?string
    {
        if ($targetPath !== null && trim($targetPath) !== '') {
            $trimmed = rtrim(trim($targetPath), DIRECTORY_SEPARATOR);
            $real = realpath($trimmed);

            return $real !== false ? $real : $trimmed;
        }

        // Check if current directory has a workspace config pointing to a target
        $cwd = getcwd() ?: '.';
        $cwdJson = $cwd . '/.lara-orcest/config.json';
        if (file_exists($cwdJson)) {
            $data = json_decode((string) file_get_contents($cwdJson), true);
            if (is_array($data) && ! empty($data['target_path']) && is_string($data['target_path'])) {
                $real = realpath($data['target_path']);

                return $real !== false ? $real : rtrim($data['target_path'], DIRECTORY_SEPARATOR);
            }
        }

        $realCwd = realpath($cwd);

        return $realCwd !== false ? $realCwd : $cwd;
    }

    /**
     * Retrieve a configuration item using dot-notation.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        // Check direct key
        if (array_key_exists($key, $this->data)) {
            return $this->data[$key];
        }

        // Strip 'lara-orcest.' prefix if provided
        $normalizedKey = str_starts_with($key, 'lara-orcest.')
            ? substr($key, strlen('lara-orcest.'))
            : $key;

        if (array_key_exists($normalizedKey, $this->data)) {
            return $this->data[$normalizedKey];
        }

        // Traverse dot-notation
        $segments = explode('.', $normalizedKey);
        $current = $this->data;

        foreach ($segments as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                // Also check if original key was inside a 'lara-orcest' top-level key
                if (isset($this->data['lara-orcest']) && is_array($this->data['lara-orcest'])) {
                    $subCurrent = $this->data['lara-orcest'];
                    foreach ($segments as $subSegment) {
                        if (! is_array($subCurrent) || ! array_key_exists($subSegment, $subCurrent)) {
                            return $default;
                        }
                        $subCurrent = $subCurrent[$subSegment];
                    }

                    return $subCurrent;
                }

                return $default;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * Set a configuration item using dot-notation.
     */
    public function set(string $key, mixed $value): self
    {
        $normalizedKey = str_starts_with($key, 'lara-orcest.')
            ? substr($key, strlen('lara-orcest.'))
            : $key;

        $segments = explode('.', $normalizedKey);
        $current = &$this->data;

        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $current[$segment] = $value;
            } else {
                if (! isset($current[$segment]) || ! is_array($current[$segment])) {
                    $current[$segment] = [];
                }
                $current = &$current[$segment];
            }
        }

        return $this;
    }

    /**
     * Checks if a configuration key exists.
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Returns all configuration data.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * Merge new values into configuration.
     *
     * @param  array<string, mixed>  $values
     */
    public function merge(array $values): self
    {
        $this->data = self::mergeArrays($this->data, $values);

        return $this;
    }

    public function getTargetProjectPath(): ?string
    {
        return $this->targetProjectPath;
    }

    public function setTargetProjectPath(?string $path): self
    {
        $this->targetProjectPath = $path !== null ? rtrim($path, DIRECTORY_SEPARATOR) : null;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * Deep merge two associative arrays.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $replacement
     * @return array<string, mixed>
     */
    protected static function mergeArrays(array $base, array $replacement): array
    {
        foreach ($replacement as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                // If it's a list (indexed array), replacement overwrites base
                if (array_is_list($value) && array_is_list($base[$key])) {
                    $base[$key] = $value;
                } else {
                    $base[$key] = self::mergeArrays($base[$key], $value);
                }
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}
