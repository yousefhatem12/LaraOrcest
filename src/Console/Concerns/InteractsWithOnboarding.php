<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Console\Concerns;

use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Yousef\LaraOrcest\Config\EnvCredentialManager;
use Yousef\LaraOrcest\Config\OrchestratorConfig;

trait InteractsWithOnboarding
{
    /**
     * Determine whether the current input can safely prompt interactively without hanging.
     */
    protected function canPromptInteractively(InputInterface $input): bool
    {
        if (! $input->isInteractive()) {
            return false;
        }

        // ApplicationTester without setInputs() uses ArrayInput with null stream and would block on STDIN.
        if ($input instanceof ArrayInput && $input->getStream() === null) {
            return false;
        }

        return true;
    }

    /**
     * Prompt the developer in the terminal to choose or confirm the target Laravel version.
     */
    protected function promptLaravelVersion(
        SymfonyStyle $io,
        string $default = '^12.0',
        ?string $promptText = null
    ): string {
        $question = $promptText ?? 'Which Laravel version would you like to install? (e.g. ^12.0, ^11.0)';
        $answer = $io->ask($question, $default);
        $version = is_string($answer) ? trim($answer) : '';

        if ($version === '') {
            $version = $default;
        }

        $io->text("Selected Laravel Version: <info>{$version}</info>");

        return $version;
    }

    /**
     * Read any saved Laravel version from the target workspace config if present.
     */
    protected function readSavedLaravelVersion(string $targetProjectPath): ?string
    {
        $jsonFile = rtrim($targetProjectPath, DIRECTORY_SEPARATOR) . '/.lara-orcest/config.json';
        if (is_file($jsonFile)) {
            $decoded = json_decode((string) file_get_contents($jsonFile), true);
            if (is_array($decoded)) {
                $saved = $decoded['package_manager']['laravel_version']
                    ?? $decoded['package_manager']['default_laravel_version']
                    ?? null;
                if (is_string($saved) && trim($saved) !== '') {
                    return trim($saved);
                }
            }
        }

        $phpFile = rtrim($targetProjectPath, DIRECTORY_SEPARATOR) . '/.lara-orcest/config.php';
        if (is_file($phpFile)) {
            $loaded = require $phpFile;
            if (is_array($loaded)) {
                $saved = $loaded['package_manager']['laravel_version']
                    ?? $loaded['package_manager']['default_laravel_version']
                    ?? null;
                if (is_string($saved) && trim($saved) !== '') {
                    return trim($saved);
                }
            }
        }

        return null;
    }

    /**
     * Persist the selected Laravel version into the target workspace config.json if it exists.
     */
    protected function persistLaravelVersionToWorkspaceConfig(string $targetProjectPath, string $laravelVersion): void
    {
        $jsonFile = rtrim($targetProjectPath, DIRECTORY_SEPARATOR) . '/.lara-orcest/config.json';
        if (! is_file($jsonFile)) {
            return;
        }

        $decoded = json_decode((string) file_get_contents($jsonFile), true);
        if (! is_array($decoded)) {
            return;
        }

        $decoded['package_manager'] = array_merge((array) ($decoded['package_manager'] ?? []), [
            'default_laravel_version' => $laravelVersion,
            'laravel_version' => $laravelVersion,
        ]);

        @file_put_contents($jsonFile, (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Interactively prompts for LLM provider, API key, and optional API secret,
     * persists them to .env, and hydrates runtime configuration immediately.
     */
    protected function promptAndPersistLlmCredentials(
        SymfonyStyle $io,
        OrchestratorConfig $config,
        string $targetProjectPath,
        ?string $presetProvider = null
    ): string {
        $providers = EnvCredentialManager::supportedProviders();
        $providerKeys = array_keys($providers);

        if ($presetProvider !== null && trim($presetProvider) !== '') {
            $normalized = EnvCredentialManager::normalizeDriverName($presetProvider);
            $selectedProvider = $normalized === 'openai_compatible' ? 'custom' : $normalized;
            if (! isset($providers[$selectedProvider])) {
                $selectedProvider = 'custom';
            }
            $io->note("LLM provider [{$normalized}] is missing credentials. Please enter your credentials below.");
        } else {
            $choiceLabels = [];
            foreach ($providers as $key => $spec) {
                $choiceLabels[$key] = $spec['label'];
            }
            $selected = (string) $io->choice('Select your LLM provider', $choiceLabels, 'groq');
            $selectedProvider = isset($providers[$selected]) ? $selected : (string) (array_search($selected, $choiceLabels, true) ?: 'groq');
        }

        $spec = $providers[$selectedProvider] ?? $providers['custom'];
        $baseUrl = null;
        $model = null;
        $apiKey = '';
        $apiSecret = '';

        if (! $spec['requires_key']) {
            $baseUrlAnswer = $io->ask("Enter {$selectedProvider} Base URL", $spec['default_base_url']);
            $baseUrl = is_string($baseUrlAnswer) && trim($baseUrlAnswer) !== '' ? trim($baseUrlAnswer) : $spec['default_base_url'];

            $modelAnswer = $io->ask("Enter {$selectedProvider} model", $spec['default_model']);
            $model = is_string($modelAnswer) && trim($modelAnswer) !== '' ? trim($modelAnswer) : $spec['default_model'];
        } else {
            if ($selectedProvider === 'custom') {
                $baseUrlAnswer = $io->ask('Enter OpenAI-compatible API Base URL', $spec['default_base_url']);
                $baseUrl = is_string($baseUrlAnswer) && trim($baseUrlAnswer) !== '' ? trim($baseUrlAnswer) : $spec['default_base_url'];

                $modelAnswer = $io->ask('Enter model name', $spec['default_model']);
                $model = is_string($modelAnswer) && trim($modelAnswer) !== '' ? trim($modelAnswer) : $spec['default_model'];
            }

            $keyEnvName = $spec['api_key_env'] ?? 'LARA_ORCEST_LLM_API_KEY';
            $apiKey = (string) $io->askHidden(
                "Enter {$selectedProvider} API key ({$keyEnvName})",
                static function (mixed $value): string {
                    $trimmed = is_string($value) ? trim($value) : '';
                    if ($trimmed === '') {
                        throw new RuntimeException('API key cannot be empty.');
                    }

                    return $trimmed;
                }
            );

            $secretAnswer = $io->askHidden('Enter API secret (optional, press Enter to skip)');
            $apiSecret = is_string($secretAnswer) ? trim($secretAnswer) : '';
        }

        $variables = EnvCredentialManager::buildEnvVariables(
            provider: $selectedProvider,
            apiKey: $apiKey,
            apiSecret: $apiSecret,
            baseUrl: $baseUrl,
            model: $model
        );

        $envPath = EnvCredentialManager::resolveTargetEnvPath($targetProjectPath);
        EnvCredentialManager::persistToEnvFile($envPath, $variables, ensureGitIgnore: true);
        $resolvedDriver = EnvCredentialManager::hydrateRuntime($variables, $selectedProvider, $config);

        $io->text("Saved LLM credentials [<info>{$resolvedDriver}</info>] to <info>{$envPath}</info>");

        return $resolvedDriver;
    }
}
