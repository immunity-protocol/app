<?php

declare(strict_types=1);

namespace App\Models\Core;

use Zephyrus\Core\Config\DatabaseConfig;
use Zephyrus\Data\Database;

/**
 * Database factory that applies project-wide type conversions.
 *
 * Zephyrus' built-in NUMERIC -> float conversion loses precision on money
 * columns (e.g. "500.000000" -> 500). Money values flow as strings end to
 * end so we override NUMERIC / DECIMAL / NEWDECIMAL to keep the raw text.
 */
final class Db
{
    public static function fromConfig(DatabaseConfig $config): Database
    {
        $db = Database::fromConfig($config);
        $stringPassthrough = static fn (string $v): string => $v;
        $db->registerTypeConversion('NUMERIC', $stringPassthrough);
        $db->registerTypeConversion('DECIMAL', $stringPassthrough);
        $db->registerTypeConversion('NEWDECIMAL', $stringPassthrough);
        return $db;
    }
}
