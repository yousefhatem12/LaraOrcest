<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Config;

use Dotenv\Dotenv;
use Yousef\LaraOrcest\Support\Config;

class EnvCredentialManager
{
    /**
     * @return array<string, array{
     *     label: string,
     *     driver: string,
     *     requires_key: bool,
     *     api_key_env: ?string,
     *     base_url_env: string,
     *     model_env: string,
     *     default_base_url: string,
     *     default_model: string
     * }>
     */
    public static function supportedProviders(): array
    {
        return [
            'groq' => [
                'label' => 'Groq (OpenAI-compatible)',
                'driver' => 'groq',
                'requires_key' => true,
                'api_key_env' => 'GROQ_API_KEY',
                'base_url_env' => 'GROQ_BASE_URL',
                'model_env' => 'LARA_ORCEST_GROQ_MODEL',
                'default_base_url' => 'https://api.groq.com/openai/v1',
                'default_model' => 'llama-3.3-70b-versatile',
            ],
            'openai' => [
                'label' => 'OpenAI',
                'driver' => 'openai',
                'requires_key' => true,
                'api_key_env' => 'OPENAI_API_KEY',
                'base_url_env' => 'OPENAI_BASE_URL',
                'model_env' => 'LARA_ORCEST_OPENAI_MODEL',
                'default_base_url' => 'https://api.openai.com/v1',
                'default_model' => 'gpt-4o',
            ],
            'anthropic' => [
                'label' => 'Anthropic (Claude)',
                'driver' => 'anthropic',
                'requires_key' => true,
                'api_key_env' => 'ANTHROPIC_API_KEY',
                'base_url_env' => 'ANTHROPIC_BASE_URL',
                'model_env' => 'LARA_ORCEST_ANTHROPIC_MODEL',
                'default_base_url' => 'https://api.anthropic.com/v1',
                'default_model' => 'claude-3-5-sonnet-20241022',
            ],
            'gemini' => [
                'label' => 'Google Gemini',
                'driver' => 'gemini',
                'requires_key' => true,
                'api_key_env' => 'GEMINI_API_KEY',
                'base_url_env' => 'GEMINI_BASE_URL',
                'model_env' => 'LARA_ORCEST_GEMINI_MODEL',
                'default_base_url' => 'https://generativelanguage.googleapis.com/v1beta',
                'default_model' => 'gemini-2.0-flash',
            ],
            'ollama' => [
                'label' => 'Ollama (Local, no API key)',
                'driver' => 'ollama',
                'requires_key' => false,
                'api_key_env' => null,
                'base_url_env' => 'OLLAMA_BASE_URL',
                'model_env' => 'LARA_ORCEST_OLLAMA_MODEL',
                'default_base_url' => 'http://127.0.0.1:11434',
                'default_model' => 'llama3.3:latest',
            ],
            'openrouter' => [
                'label' => 'OpenRouter (OpenAI-compatible)',
                'driver' => 'openrouter',
                'requires_key' => true,
                'api_key_env' => 'OPENROUTER_API_KEY',
                'base_url_env' => 'OPENROUTER_BASE_URL',
                'model_env' => 'LARA_ORCEST_OPENROUTER_MODEL',
                'default_base_url' => 'https://openrouter.ai/api/v1',
                'default_model' => 'openai/gpt-4o-mini',
            ],
            'custom' => [
                'label' => 'Custom OpenAI-compatible Endpoint',
                'driver' => 'openai_compatible',
                'requires_key' => true,
                'api_key_env' => 'LARA_ORCEST_LLM_API_KEY',
                'base_url_env' => 'LARA_ORCEST_LLM_BASE_URL',
                'model_env' => 'LARA_ORCEST_LLM_MODEL',
                'default_base_url' => 'https://api.openai.com/v1',
                'default_model' => 'gpt-4o-mini',
            ],
        ];
    }

    /**
     * Loads .env files from the target workspace and/or current working directory.
     */
    public static function loadEnvFiles(?string $targetProjectPath = null, ?string $workingDirectory = null): void
    {
        $candidates = [];

        if ($targetProjectPath !== null && trim($targetProjectPath) !== '') {
            $candidates[] = rtrim(trim($targetProjectPath), DIRECTORY_SEPARATOR);
        }

        $cwd = $workingDirectory ?? (getcwd() ?: null);
        if ($cwd !== null && trim($cwd) !== '') {
            $candidates[] = rtrim(trim($cwd), DIRECTORY_SEPARATOR);
        }

        $seen = [];
        foreach ($candidates as $dir) {
            $real = realpath($dir) ?: $dir;
            if (isset($seen[$real])) {
                continue;
            }
            $seen[$real] = true;

            if (is_file($real . DIRECTORY_SEPARATOR . '.env')) {
                try {
                    Dotenv::createImmutable($real)->safeLoad();
                } catch (\Throwable) {
                    // Ignore Dotenv parse errors on malformed .env
                }
            }
        }
    }

    /**
     * Reads a non-empty environment variable across $_ENV, $_SERVER, and getenv().
     */
    public static function getEnv(string $key): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * Normalizes provider/driver aliases to canonical LlmManager driver names.
     */
    public static function normalizeDriverName(string $name): string
    {
        return match (strtolower(trim($name))) {
            'openai-compatible', 'custom' => 'openai_compatible',
            default => strtolower(trim($name)),
        };
    }

    /**
     * Resolves the currently configured LLM driver or auto-detects one from configured keys.
     */
    public static function resolveActiveDriver(?string $explicitDriver, OrchestratorConfig $config): ?string
    {
        if ($explicitDriver !== null && trim($explicitDriver) !== '') {
            return self::normalizeDriverName($explicitDriver);
        }

        $envDriver = self::getEnv('LARA_ORCEST_LLM');
        if ($envDriver !== null) {
            return self::normalizeDriverName($envDriver);
        }

        $configuredDefault = $config->get('llm.default');
        if (is_string($configuredDefault) && trim($configuredDefault) !== '' && trim($configuredDefault) !== 'openai_compatible') {
            return self::normalizeDriverName($configuredDefault);
        }

        // Auto-detect from any configured provider key in env or config
        if (self::isNonEmpty(self::getEnv('LARA_ORCEST_LLM_API_KEY')) || self::isNonEmpty($config->get('llm.drivers.openai_compatible.api_key'))) {
            return 'openai_compatible';
        }
        if (self::isNonEmpty(self::getEnv('OPENAI_API_KEY')) || self::isNonEmpty($config->get('llm.providers.openai.api_key'))) {
            return 'openai';
        }
        if (self::isNonEmpty(self::getEnv('GROQ_API_KEY')) || self::isNonEmpty($config->get('llm.providers.groq.api_key'))) {
            return 'groq';
        }
        if (self::isNonEmpty(self::getEnv('ANTHROPIC_API_KEY')) || self::isNonEmpty($config->get('llm.providers.anthropic.api_key'))) {
            return 'anthropic';
        }
        if (self::isNonEmpty(self::getEnv('GEMINI_API_KEY')) || self::isNonEmpty($config->get('llm.providers.gemini.api_key'))) {
            return 'gemini';
        }
        if (self::isNonEmpty(self::getEnv('OPENROUTER_API_KEY')) || self::isNonEmpty($config->get('llm.providers.openrouter.api_key'))) {
            return 'openrouter';
        }

        return null;
    }

    /**
     * Checks whether usable credentials exist for the specified driver.
     */
    public static function hasCredentialsForDriver(string $driver, OrchestratorConfig $config): bool
    {
        $normalized = self::normalizeDriverName($driver);

        if ($normalized === 'fake' || $normalized === 'ollama') {
            return true;
        }

        return match ($normalized) {
            'openai_compatible' => self::isNonEmpty(self::getEnv('LARA_ORCEST_LLM_API_KEY'))
                || self::isNonEmpty(self::getEnv('OPENAI_API_KEY'))
                || self::isNonEmpty($config->get('llm.drivers.openai_compatible.api_key'))
                || self::isNonEmpty($config->get('llm.providers.openai.api_key')),
            'openai' => self::isNonEmpty(self::getEnv('OPENAI_API_KEY'))
                || self::isNonEmpty(self::getEnv('LARA_ORCEST_LLM_API_KEY'))
                || self::isNonEmpty($config->get('llm.providers.openai.api_key'))
                || self::isNonEmpty($config->get('llm.drivers.openai_compatible.api_key')),
            'groq' => self::isNonEmpty(self::getEnv('GROQ_API_KEY'))
                || self::isNonEmpty(self::getEnv('LARA_ORCEST_LLM_API_KEY'))
                || self::isNonEmpty($config->get('llm.providers.groq.api_key'))
                || self::isNonEmpty($config->get('llm.drivers.openai_compatible.api_key')),
            'openrouter' => self::isNonEmpty(self::getEnv('OPENROUTER_API_KEY'))
                || self::isNonEmpty(self::getEnv('LARA_ORCEST_LLM_API_KEY'))
                || self::isNonEmpty($config->get('llm.providers.openrouter.api_key'))
                || self::isNonEmpty($config->get('llm.drivers.openai_compatible.api_key')),
            'anthropic' => self::isNonEmpty(self::getEnv('ANTHROPIC_API_KEY'))
                || self::isNonEmpty(self::getEnv('LARA_ORCEST_LLM_API_KEY'))
                || self::isNonEmpty($config->get('llm.providers.anthropic.api_key')),
            'gemini' => self::isNonEmpty(self::getEnv('GEMINI_API_KEY'))
                || self::isNonEmpty(self::getEnv('LARA_ORCEST_LLM_API_KEY'))
                || self::isNonEmpty($config->get('llm.providers.gemini.api_key')),
            default => false,
        };
    }

    /**
     * Resolves the .env file path to update or create.
     */
    public static function resolveTargetEnvPath(string $targetProjectPath, ?string $workingDirectory = null): string
    {
        $targetDir = rtrim($targetProjectPath, DIRECTORY_SEPARATOR);
        $targetEnv = $targetDir . DIRECTORY_SEPARATOR . '.env';
        if (is_file($targetEnv)) {
            return $targetEnv;
        }

        $cwd = $workingDirectory ?? (getcwd() ?: null);
        if ($cwd !== null && trim($cwd) !== '') {
            $cwdDir = rtrim(trim($cwd), DIRECTORY_SEPARATOR);
            $realTarget = realpath($targetDir) ?: $targetDir;
            $realCwd = realpath($cwdDir) ?: $cwdDir;
            $cwdEnv = $realCwd . DIRECTORY_SEPARATOR . '.env';
            if ($realCwd !== $realTarget && is_file($cwdEnv)) {
                return $cwdEnv;
            }
        }

        return $targetEnv;
    }

    /**
     * Builds the environment key-value map for a selected provider and credentials.
     *
     * @return array<string, string>
     */
    public static function buildEnvVariables(
        string $provider,
        string $apiKey = '',
        string $apiSecret = '',
        ?string $baseUrl = null,
        ?string $model = null
    ): array {
        $providers = self::supportedProviders();
        $key = strtolower(trim($provider));
        if ($key === 'openai_compatible' || $key === 'openai-compatible') {
            $key = 'custom';
        }

        $spec = $providers[$key] ?? $providers['custom'];
        $vars = [
            'LARA_ORCEST_LLM' => $spec['driver'],
        ];

        if ($spec['api_key_env'] !== null && trim($apiKey) !== '') {
            $vars[$spec['api_key_env']] = trim($apiKey);
        }

        if (trim($apiSecret) !== '') {
            $vars['LARA_ORCEST_LLM_API_SECRET'] = trim($apiSecret);
        }

        $resolvedBaseUrl = $baseUrl !== null && trim($baseUrl) !== '' ? trim($baseUrl) : null;
        if ($resolvedBaseUrl !== null) {
            $vars[$spec['base_url_env']] = $resolvedBaseUrl;
        } elseif (in_array($key, ['groq', 'openrouter', 'ollama'], true)) {
            $vars[$spec['base_url_env']] = $spec['default_base_url'];
        }

        $resolvedModel = $model !== null && trim($model) !== '' ? trim($model) : null;
        if ($resolvedModel !== null) {
            $vars[$spec['model_env']] = $resolvedModel;
        } elseif (in_array($key, ['groq', 'openrouter', 'ollama'], true)) {
            $vars[$spec['model_env']] = $spec['default_model'];
        }

        return $vars;
    }

    /**
     * Persists environment variables into the given .env file without duplicating existing keys.
     *
     * @param  array<string, string>  $variables
     */
    public static function persistToEnvFile(string $envFilePath, array $variables, bool $ensureGitIgnore = true): void
    {
        $dir = dirname($envFilePath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $existingContent = is_file($envFilePath) ? (string) file_get_contents($envFilePath) : '';
        $normalizedContent = str_replace(["\r\n", "\r"], "\n", $existingContent);
        $lines = $normalizedContent === '' ? [] : explode("\n", $normalizedContent);

        // Trim single trailing empty element produced by trailing newline
        if (! empty($lines) && end($lines) === '') {
            array_pop($lines);
        }

        $remaining = $variables;
        $updatedLines = [];

        foreach ($lines as $line) {
            $replaced = false;
            foreach ($remaining as $key => $value) {
                if (preg_match('/^\s*(?:export\s+)?' . preg_quote($key, '/') . '\s*=/', $line) === 1) {
                    $updatedLines[] = $key . '=' . self::formatEnvValue($value);
                    unset($remaining[$key]);
                    $replaced = true;
                    break;
                }
            }
            if (! $replaced) {
                $updatedLines[] = $line;
            }
        }

        foreach ($remaining as $key => $value) {
            $updatedLines[] = $key . '=' . self::formatEnvValue($value);
        }

        $output = implode("\n", $updatedLines) . "\n";
        file_put_contents($envFilePath, $output, LOCK_EX);

        if ($ensureGitIgnore) {
            self::ensureEnvGitIgnored($dir);
        }
    }

    /**
     * Hydrates runtime superglobals, putenv(), OrchestratorConfig, and Config bridge immediately.
     *
     * @param  array<string, string>  $variables
     */
    public static function hydrateRuntime(array $variables, string $provider, OrchestratorConfig $config): string
    {
        foreach ($variables as $key => $value) {
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv("{$key}={$value}");
        }

        $providers = self::supportedProviders();
        $key = strtolower(trim($provider));
        if ($key === 'openai_compatible' || $key === 'openai-compatible') {
            $key = 'custom';
        }
        $spec = $providers[$key] ?? $providers['custom'];
        $driver = $spec['driver'];

        $config->set('llm.default', $driver);
        Config::set('lara-orcest.llm.default', $driver);

        if (isset($variables['LARA_ORCEST_LLM_API_SECRET'])) {
            $config->set('llm.api_secret', $variables['LARA_ORCEST_LLM_API_SECRET']);
            Config::set('lara-orcest.llm.api_secret', $variables['LARA_ORCEST_LLM_API_SECRET']);
        }

        if ($spec['api_key_env'] !== null && isset($variables[$spec['api_key_env']])) {
            $apiKey = $variables[$spec['api_key_env']];
            if ($driver === 'openai_compatible') {
                $config->set('llm.drivers.openai_compatible.api_key', $apiKey);
                Config::set('lara-orcest.llm.drivers.openai_compatible.api_key', $apiKey);
            } else {
                $config->set("llm.providers.{$driver}.api_key", $apiKey);
                Config::set("lara-orcest.llm.providers.{$driver}.api_key", $apiKey);
            }
        }

        if (isset($variables[$spec['base_url_env']])) {
            $baseUrl = $variables[$spec['base_url_env']];
            if ($driver === 'openai_compatible') {
                $config->set('llm.drivers.openai_compatible.base_url', $baseUrl);
                Config::set('lara-orcest.llm.drivers.openai_compatible.base_url', $baseUrl);
            } else {
                $config->set("llm.providers.{$driver}.base_url", $baseUrl);
                Config::set("lara-orcest.llm.providers.{$driver}.base_url", $baseUrl);
            }
        }

        if (isset($variables[$spec['model_env']])) {
            $model = $variables[$spec['model_env']];
            if ($driver === 'openai_compatible') {
                $config->set('llm.drivers.openai_compatible.model', $model);
                Config::set('lara-orcest.llm.drivers.openai_compatible.model', $model);
            } else {
                $config->set("llm.providers.{$driver}.model", $model);
                Config::set("lara-orcest.llm.providers.{$driver}.model", $model);
            }
        }

        return $driver;
    }

    /**
     * Ensures .env is listed in the directory's .gitignore file.
     */
    public static function ensureEnvGitIgnored(string $directory): void
    {
        $gitignorePath = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.gitignore';

        if (is_file($gitignorePath)) {
            $content = (string) file_get_contents($gitignorePath);
            $lines = preg_split('/\R/', $content) ?: [];
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if ($trimmed === '.env' || $trimmed === '/.env' || $trimmed === '.env*') {
                    return;
                }
            }

            $prefix = ($content !== '' && ! str_ends_with($content, "\n")) ? "\n" : '';
            @file_put_contents($gitignorePath, $content . $prefix . ".env\n");

            return;
        }

        @file_put_contents($gitignorePath, ".env\n.env.*\n");
    }

    /**
     * Formats an actionable error message for non-interactive environments missing credentials.
     */
    public static function describeMissingCredentials(?string $driver, string $envFilePath): string
    {
        if ($driver !== null && $driver !== '') {
            $normalized = self::normalizeDriverName($driver);
            $keyMap = [
                'groq' => 'GROQ_API_KEY',
                'openai' => 'OPENAI_API_KEY',
                'anthropic' => 'ANTHROPIC_API_KEY',
                'gemini' => 'GEMINI_API_KEY',
                'openrouter' => 'OPENROUTER_API_KEY',
                'openai_compatible' => 'LARA_ORCEST_LLM_API_KEY (or OPENAI_API_KEY)',
            ];
            $expectedVar = $keyMap[$normalized] ?? 'LARA_ORCEST_LLM_API_KEY';

            return "LLM credentials are missing for provider [{$normalized}]. Set {$expectedVar} (and optional LARA_ORCEST_LLM_API_SECRET) in [{$envFilePath}] or your environment, run interactively without --no-interaction, or pass --llm=fake / --llm=ollama.";
        }

        return "No LLM provider or API key is configured. Set LARA_ORCEST_LLM and a provider key (e.g. GROQ_API_KEY, OPENAI_API_KEY, ANTHROPIC_API_KEY, GEMINI_API_KEY, OPENROUTER_API_KEY, or LARA_ORCEST_LLM_API_KEY, plus optional LARA_ORCEST_LLM_API_SECRET) in [{$envFilePath}] or your environment, run interactively without --no-interaction, or pass --llm=fake / --llm=ollama.";
    }

    protected static function formatEnvValue(string $value): string
    {
        if ($value === '') {
            return '""';
        }

        if (preg_match('/^[A-Za-z0-9_.\-:\/+^~=@]+$/', $value) === 1) {
            return $value;
        }

        $escaped = str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value);

        return '"' . $escaped . '"';
    }

    protected static function isNonEmpty(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
