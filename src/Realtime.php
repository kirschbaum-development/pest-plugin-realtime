<?php

declare(strict_types=1);

namespace Pest\Realtime;

use Pest\Browser\Playwright\Playwright;
use Pest\Realtime\Contracts\Driver;
use Pest\Realtime\Drivers\EchoPusherDriver;

/**
 * Package-wide defaults, usually set once in `tests/Pest.php`.
 */
final class Realtime
{
    private static ?Driver $driver = null;

    private static ?int $timeout = null;

    /**
     * Gets the default driver, or sets it when one is given.
     */
    public static function driver(?Driver $driver = null): Driver
    {
        if ($driver instanceof Driver) {
            self::$driver = $driver;
        }

        return self::$driver ??= new EchoPusherDriver();
    }

    /**
     * Gets the default timeout in milliseconds, or sets it when one is given.
     *
     * Falls back to Pest Browser's own assertion timeout so tuning one tunes both.
     */
    public static function timeout(?int $milliseconds = null): int
    {
        if ($milliseconds !== null) {
            self::$timeout = $milliseconds;
        }

        return self::$timeout ?? Playwright::timeout();
    }

    public static function flush(): void
    {
        self::$driver = null;
        self::$timeout = null;
    }
}
