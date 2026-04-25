<?php

declare(strict_types=1);

namespace App\Models\Core;

use Zephyrus\Core\App;
use Zephyrus\Core\Config\DatabaseConfig;
use Zephyrus\Data\Database;

/**
 * Database factory with project-wide type conversions and a singleton accessor.
 *
 * `current()` returns a lazily-built Database from the live Configuration so
 * brokers do not need explicit dependency injection at every call site.
 *
 * Type conversions:
 *   - NUMERIC / DECIMAL / NEWDECIMAL: kept as strings to preserve money precision.
 */
final class Db
{
    private static ?Database $current = null;

    public static function fromConfig(DatabaseConfig $config): Database
    {
        $db = Database::fromConfig($config);
        $stringPassthrough = static fn (string $v): string => $v;
        $db->registerTypeConversion('NUMERIC', $stringPassthrough);
        $db->registerTypeConversion('DECIMAL', $stringPassthrough);
        $db->registerTypeConversion('NEWDECIMAL', $stringPassthrough);
        return $db;
    }

    public static function current(): Database
    {
        if (self::$current !== null) {
            return self::$current;
        }
        $config = App::getConfiguration()?->database
            ?? throw new \RuntimeException('Db::current() called before Configuration was bootstrapped.');
        self::$current = self::fromConfig($config);
        return self::$current;
    }

    public static function setCurrent(?Database $db): void
    {
        self::$current = $db;
    }
}
