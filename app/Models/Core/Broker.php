<?php

declare(strict_types=1);

namespace App\Models\Core;

use stdClass;
use Zephyrus\Data\Broker as ZephyrusBroker;
use Zephyrus\Data\Database;

/**
 * Project broker base.
 *
 * Defaults the Database to the current request-scoped instance so callers
 * can write `new EntryBroker()` without explicit DI. Tests inject a
 * transactional Database explicitly.
 *
 * Sanitizes bytea cells from PDO_PGSQL stream resources to PHP strings
 * before returning rows; Zephyrus' built-in type-conversion path skips
 * resources, and downstream entity hydration assumes string values.
 */
abstract class Broker extends ZephyrusBroker
{
    public function __construct(?Database $db = null)
    {
        parent::__construct($db ?? Db::current());
    }

    protected function select(string $sql, array $params = []): array
    {
        $rows = parent::select($sql, $params);
        foreach ($rows as $row) {
            self::sanitize($row);
        }
        return $rows;
    }

    protected function selectOne(string $sql, array $params = []): ?stdClass
    {
        $row = parent::selectOne($sql, $params);
        if ($row !== null) {
            self::sanitize($row);
        }
        return $row;
    }

    private static function sanitize(stdClass $row): void
    {
        foreach ($row as $key => $value) {
            if (is_resource($value)) {
                $contents = stream_get_contents($value);
                $row->$key = $contents === false ? '' : $contents;
            }
        }
    }
}
