<?php

declare(strict_types=1);

namespace App\Models\Indexer\Handlers;

use Zephyrus\Data\Database;

/**
 * Corroboration denormalization (Phase 2). An antibody is corroborated when
 * multiple distinct publishers independently publish the same primary matcher.
 * On each Published event we recompute corroboration_count = the distinct
 * publisher count for that primary_matcher_hash and write it to every entry in
 * the group, so list/detail pages can show corroboration without a join.
 *
 * Recompute (not increment) → idempotent under backfill replay.
 */
class CorroborationHandler
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @param array<string,mixed> $decoded Registry.Published */
    public function handle(array $decoded): bool
    {
        $hash = strtolower(self::stripHex((string) ($decoded['args']['primaryMatcherHash'] ?? '')));
        if ($hash === '' || ltrim($hash, '0') === '') {
            return false;
        }

        $row = $this->db->query(
            "UPDATE antibody.entry
                SET corroboration_count = sub.n, updated_at = now()
              FROM (
                  SELECT count(DISTINCT publisher) AS n
                    FROM antibody.entry
                   WHERE primary_matcher_hash = ?
              ) sub
              WHERE primary_matcher_hash = ?
              RETURNING antibody.entry.id",
            ['\\x' . $hash, '\\x' . $hash]
        );
        return $row->fetch(\PDO::FETCH_ASSOC) !== false;
    }

    private static function stripHex(string $hex): string
    {
        if (str_starts_with($hex, '0x') || str_starts_with($hex, '0X')) {
            return substr($hex, 2);
        }
        return $hex;
    }
}
