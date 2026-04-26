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

    /**
     * Parse DATABASE_URL (postgres://user:pass@host:port/db) and inject the
     * pieces into $_ENV / putenv so any subsequent `!env` reference in
     * config.yml resolves to the right value. Existing DB_* vars take
     * precedence so callers can override individual fields.
     *
     * Used by Kernel::boot and the bin/* CLI scripts that don't go through
     * the Kernel.
     */
    public static function applyDatabaseUrl(): void
    {
        $url = $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL') ?: null;
        if (!is_string($url) || $url === '') {
            return;
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return;
        }
        $set = static function (string $key, ?string $value): void {
            if ($value === null || $value === '') {
                return;
            }
            if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
                return;
            }
            $_ENV[$key] = $value;
            putenv($key . '=' . $value);
        };
        $set('DB_HOST',     $parts['host']     ?? null);
        $set('DB_PORT',     isset($parts['port']) ? (string) $parts['port'] : null);
        $set('DB_USERNAME', isset($parts['user']) ? rawurldecode($parts['user']) : null);
        $set('DB_PASSWORD', isset($parts['pass']) ? rawurldecode($parts['pass']) : null);
        $set('DB_NAME',     isset($parts['path']) ? ltrim($parts['path'], '/') : null);
    }
}
