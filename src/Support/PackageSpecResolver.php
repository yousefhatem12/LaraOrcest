<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Support;

use Yousef\LaraOrcest\State\RunState;

class PackageSpecResolver
{
    /**
     * Resolve the target Laravel framework/skeleton version constraint from state or config.
     */
    public static function resolveTargetLaravelVersion(RunState $state): string
    {
        $metaVersion = $state->metadata['package_manager']['laravel_version'] ?? null;
        if (is_string($metaVersion) && trim($metaVersion) !== '') {
            return trim($metaVersion);
        }

        $planVersion = $state->technicalPlan['laravel_version'] ?? null;
        if (is_string($planVersion) && trim($planVersion) !== '') {
            return trim($planVersion);
        }

        return (string) Config::get('lara-orcest.package_manager.default_laravel_version', '^11.0|^12.0');
    }

    /**
     * Inspect an existing target workspace composer.json for an installed laravel/framework constraint.
     */
    public static function detectInstalledLaravelVersion(?string $targetPath): ?string
    {
        if ($targetPath === null || ! is_dir($targetPath)) {
            return null;
        }

        $composerFile = rtrim($targetPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'composer.json';
        if (! is_file($composerFile)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($composerFile), true);
        if (! is_array($decoded)) {
            return null;
        }

        $constraint = $decoded['require']['laravel/framework'] ?? null;

        return is_string($constraint) && trim($constraint) !== '' ? trim($constraint) : null;
    }

    /**
     * Build the package argument passed to `composer create-project`.
     * Omits compound constraints containing shell metacharacters (such as '|') to respect ComposerRunnerTool rules.
     */
    public static function resolveCreateProjectTarget(string $targetVersion): string
    {
        $trimmed = trim($targetVersion);
        if ($trimmed !== '' && ! preg_match('/[|;&`$<>\n\r\s]/', $trimmed)) {
            return "laravel/laravel:{$trimmed}";
        }

        return 'laravel/laravel';
    }

    /**
     * Merge LLM-proposed packages with explicit technical_plan composer_dependencies and configured defaults.
     *
     * @param  array<int, array<string, mixed>>  $llmPackages
     * @return array<int, array<string, mixed>>
     */
    public static function mergePlanDependencies(RunState $state, array $llmPackages): array
    {
        $merged = [];
        $indexByName = [];

        foreach ($llmPackages as $pkg) {
            if (! is_array($pkg)) {
                continue;
            }
            $name = trim((string) ($pkg['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $key = strtolower($name);
            $indexByName[$key] = count($merged);
            $merged[] = [
                'name' => $name,
                'version' => isset($pkg['version']) && trim((string) $pkg['version']) !== '' ? trim((string) $pkg['version']) : null,
                'dev' => (bool) ($pkg['dev'] ?? false),
                'reason' => (string) ($pkg['reason'] ?? 'Required dependency'),
            ];
        }

        $planDeps = (array) ($state->technicalPlan['composer_dependencies'] ?? []);
        $defaultPackages = (array) Config::get('lara-orcest.package_manager.default_packages', []);

        foreach ([$planDeps, $defaultPackages] as $sourceIndex => $depList) {
            $defaultReason = $sourceIndex === 0
                ? 'Required by technical plan'
                : 'Configured default package';

            foreach ($depList as $k => $v) {
                $name = is_string($k) ? trim($k) : (is_array($v) ? trim((string) ($v['name'] ?? '')) : trim((string) $v));
                if ($name === '') {
                    continue;
                }

                $version = is_string($v) && is_string($k)
                    ? trim($v)
                    : (is_array($v) && ! empty($v['version']) ? trim((string) $v['version']) : null);
                $dev = is_array($v) ? (bool) ($v['dev'] ?? false) : false;
                $reason = is_array($v) && ! empty($v['reason']) ? (string) $v['reason'] : $defaultReason;

                $lower = strtolower($name);
                if (isset($indexByName[$lower])) {
                    $idx = $indexByName[$lower];
                    if ($merged[$idx]['version'] === null && $version !== null && $version !== '') {
                        $merged[$idx]['version'] = $version;
                    }
                    continue;
                }

                $indexByName[$lower] = count($merged);
                $merged[] = [
                    'name' => $name,
                    'version' => ($version !== null && $version !== '') ? $version : null,
                    'dev' => $dev,
                    'reason' => $reason,
                ];
            }
        }

        return $merged;
    }

    /**
     * Announce target packages and their exact version constraints to the terminal prior to installation.
     *
     * @param  array<int, array<string, mixed>>  $packages
     */
    public static function announcePackages(string $agentKey, array $packages): void
    {
        if (empty($packages)) {
            TerminalAnnouncer::announce("  [{$agentKey}] No additional Composer packages required for prompt specification.");

            return;
        }

        TerminalAnnouncer::announce("  [{$agentKey}] Disclosing target package versions before installation (driven by prompt specification):");
        foreach ($packages as $pkg) {
            $name = trim((string) ($pkg['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $version = isset($pkg['version']) && trim((string) $pkg['version']) !== '' ? trim((string) $pkg['version']) : 'latest';
            $isDev = ! empty($pkg['dev']) ? ' (dev)' : '';
            $reason = (string) ($pkg['reason'] ?? 'Required dependency');
            TerminalAnnouncer::announce("  [{$agentKey}] -> Target Package: {$name}:{$version}{$isDev} — Reason: {$reason}");
        }
    }

    /**
     * Format installed package entries into readable `name:version` strings.
     *
     * @param  array<int, array<string, mixed>>  $packages
     * @return array<int, string>
     */
    public static function formatPackageSpecs(array $packages): array
    {
        $specs = [];
        foreach ($packages as $pkg) {
            if (! is_array($pkg)) {
                continue;
            }
            $name = trim((string) ($pkg['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $version = isset($pkg['version']) && trim((string) $pkg['version']) !== '' ? trim((string) $pkg['version']) : 'latest';
            $devSuffix = ! empty($pkg['dev']) ? ' (dev)' : '';
            $specs[] = "{$name}:{$version}{$devSuffix}";
        }

        return $specs;
    }
}
