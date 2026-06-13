<?php

declare(strict_types=1);

namespace App\Models\Indexer\Handlers;

use Zephyrus\Data\Database;

/**
 * ProtectedSet.ProtectedUpdated(indexed address target, bool protected)
 *
 * Mirrors the protocol's protected-address set into antibody.protected_target
 * (Phase 2). Upsert on address; the latest event wins.
 */
class ProtectedSetHandler
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @param array<string,mixed> $decoded */
    public function handle(array $decoded): bool
    {
        $a = $decoded['args'];
        $target = strtolower(self::stripHex((string) $a['target']));
        $protected = !empty($a['protected']);

        $this->db->query(
            "INSERT INTO antibody.protected_target (address, protected, updated_at)
             VALUES (?, ?, now())
             ON CONFLICT (address) DO UPDATE SET
                protected  = EXCLUDED.protected,
                updated_at = now()",
            ['\\x' . $target, $protected ? 't' : 'f']
        );
        return true;
    }

    private static function stripHex(string $hex): string
    {
        if (str_starts_with($hex, '0x') || str_starts_with($hex, '0X')) {
            return substr($hex, 2);
        }
        return $hex;
    }
}
