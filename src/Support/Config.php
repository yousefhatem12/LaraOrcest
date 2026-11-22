<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Support;

use Illuminate\Container\Container;

class Config
{
    /**
     * @var array<string, mixed>
     */
    protected static array $overrides = [];

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

        return $default;
    }

    /**
     * Safely sets a configuration value for both the container (if bound) and static overrides.
     */
    public static function set(string $key, mixed $value): void
    {
        self::$overrides[$key] = $value;

        $container = Container::getInstance();

        if ($container !== null && $container->bound('config')) {
            $container->make('config')->set($key, $value);
        }
    }

    /**
     * Clears all in-memory static overrides.
     */
    public static function clearOverrides(): void
    {
        self::$overrides = [];
    }

    /**
     * Safely retrieves the application base path or falls back to current working directory.
     */
    public static function basePath(?string $path = null): string
    {
        $container = Container::getInstance();

        if ($container !== null && method_exists($container, 'basePath')) {
            return $container->basePath($path);
        }

        $base = getcwd() ?: '.';

        return $path !== null
            ? rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR)
            : $base;
    }
}
