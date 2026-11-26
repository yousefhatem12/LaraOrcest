<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Support;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;

class HttpClient
{
    protected static ?Factory $standaloneFactory = null;

    /**
     * Returns the active Laravel HTTP client factory when booted (preserving Http::fake() in tests),
     * or falls back to a standalone Illuminate HTTP Factory when no facade root is set.
     */
    public static function factory(): Factory
    {
        if (Facade::getFacadeApplication() !== null) {
            try {
                $root = Http::getFacadeRoot();
                if ($root instanceof Factory) {
                    return $root;
                }
            } catch (\Throwable) {
                // Fall back to standalone factory if the facade root cannot resolve Factory
            }
        }

        if (self::$standaloneFactory === null) {
            self::$standaloneFactory = new Factory();
        }

        return self::$standaloneFactory;
    }

    /**
     * Overrides the standalone factory (useful for standalone unit tests without a Laravel container).
     */
    public static function setStandaloneFactory(?Factory $factory): void
    {
        self::$standaloneFactory = $factory;
    }

    /**
     * Resets the cached standalone factory instance.
     */
    public static function reset(): void
    {
        self::$standaloneFactory = null;
    }
}
