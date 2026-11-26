<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Support;

class TerminalAnnouncer
{
    /**
     * @var array<int, string>
     */
    protected static array $history = [];

    /**
     * Optional writer callback (e.g. Symfony OutputInterface) for capturing announcements cleanly in CLI commands.
     *
     * @var callable(string): void|null
     */
    protected static $writer = null;

    /**
     * Register or clear an output writer callback.
     *
     * @param  (callable(string): void)|null  $writer
     */
    public static function setWriter(?callable $writer): void
    {
        self::$writer = $writer;
    }

    /**
     * Announce a message to the terminal and record it in announcement history.
     */
    public static function announce(string $message): void
    {
        self::$history[] = $message;

        if (self::$writer !== null) {
            (self::$writer)($message);

            return;
        }

        // Print directly to terminal in CLI environment
        if (php_sapi_name() === 'cli' || defined('STDOUT')) {
            echo $message . PHP_EOL;
        }
    }

    /**
     * Get all recorded announcements.
     *
     * @return array<int, string>
     */
    public static function getAnnouncements(): array
    {
        return self::$history;
    }

    /**
     * Flush and return recorded announcements.
     *
     * @return array<int, string>
     */
    public static function flush(): array
    {
        $records = self::$history;
        self::$history = [];

        return $records;
    }
}
