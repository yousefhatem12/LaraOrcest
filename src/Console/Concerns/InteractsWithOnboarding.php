<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Console\Concerns;

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

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
}
