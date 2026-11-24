<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Support;

use Illuminate\Container\Container;
use Yousef\LaraOrcest\Config\OrchestratorConfig;

class Config
{
    /**
     * @var array<string, mixed>
     */
    protected static array $overrides = [];

    protected static ?OrchestratorConfig $orchestratorConfig = null;

    /**
     * Set the active standalone OrchestratorConfig instance.
     */
    public static function setOrchestratorConfig(?OrchestratorConfig $config): void
    {
        self::$orchestratorConfig = $config;
    }

    /**
     * Get the active standalone OrchestratorConfig instance if set.
     */
    public static function getOrchestratorConfig(): ?OrchestratorConfig
    {
        return self::$orchestratorConfig;
    }

    /**
     * Safely retrieves a configuration value, even if the Laravel application is not booted.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$overrides)) {
            return self::$overrides[$key];
        }

        $container = Container::getInstance();

        if ($container !== null && $container->bound('config')) {
            return $container->make('config')->get($key, $default);
        }

        if (self::$orchestratorConfig !== null) {
            $val = self::$orchestratorConfig->get($key);
            if ($val !== null) {
                return $val;
            }
            if (str_starts_with($key, 'lara-orcest.')) {
                $subKey = substr($key, strlen('lara-orcest.'));
                $val = self::$orchestratorConfig->get($subKey);
                if ($val !== null) {
                    return $val;
                }
            }
        }

        return $default;
    }

    /**
     * Safely sets a configuration value for both the container (if bound) and static overrides.
     */
    public static function set(string $key, mixed $value): void
    {
        self::$overrides[$key] = $value;

        if (self::$orchestratorConfig !== null) {
            self::$orchestratorConfig->set($key, $value);
        }

        $container = Container::getInstance();

        if ($container !== null && $container->bound('config')) {
            $container->make('config')->set($key, $value);
        }
    }

    /**
     * Clears all in-memory static overrides and resets active orchestrator config.
     */
    public static function clearOverrides(): void
    {
        self::$overrides = [];
        self::$orchestratorConfig = null;
    }

    /**
     * Safely retrieves the application base path or falls back to target project path / current working directory.
     */
    public static function basePath(?string $path = null): string
    {
        $container = Container::getInstance();

        if ($container !== null && method_exists($container, 'basePath')) {
            return $container->basePath($path);
        }

        if (self::$orchestratorConfig !== null && self::$orchestratorConfig->getTargetProjectPath() !== null) {
            $base = self::$orchestratorConfig->getTargetProjectPath();
        } else {
            $base = getcwd() ?: '.';
        }

        return $path !== null
            ? rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR)
            : $base;
    }
}
