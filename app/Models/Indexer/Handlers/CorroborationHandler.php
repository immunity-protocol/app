<?php

declare(strict_types=1);

namespace App\Models\Indexer\Handlers;

use Zephyrus\Data\Database;

/**
 * Corroboration denormalization. An antibody is corroborated when several
 * DISTINCT publishers each mint their own antibody (own keccak_id) for the SAME
 * primary_matcher_hash — the path that drives maturation (corroboration==K).
 *
 * corroboration_count = COUNT(DISTINCT publisher) among non-terminal entries
 * (probation/active/challenged) sharing a primary_matcher_hash. This mirrors the
 * on-chain `_corroborationOf` minus the per-publisher reputation floor, which is
 * not observable off-chain — documented divergence. Recompute (not increment) →
 * idempotent under backfill replay; re-run on Published (a new corroborator),
 * Slashed and Expired (a corroborator drops out).
 */
class CorroborationHandler
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @param array<string,mixed> $decoded Registry.Published — hash is in the event. */
    public function handlePublished(array $decoded): bool
    {
        $hash = strtolower(self::stripHex((string) ($decoded['args']['primaryMatcherHash'] ?? '')));
        if (self::isZero($hash)) {
            return false;
        }
        return $this->recomputeForHash($hash);
    }

    /**
     * @param array<string,mixed> $decoded Registry.Slashed/Expired/Retired — only
     *        the keccakId is in the event, so resolve the entry's matcher hash first.
     */
    public function handleByKeccak(array $decoded): bool
    {
        $keccak = strtolower(self::stripHex((string) ($decoded['args']['keccakId'] ?? '')));
        if (self::isZero($keccak)) {
            return false;
        }
        $row = $this->db->query(
            "SELECT encode(primary_matcher_hash, 'hex') AS h FROM antibody.entry WHERE keccak_id = ?",
            ['\\x' . $keccak]
        )->fetch(\PDO::FETCH_OBJ);
        if ($row === false || $row->h === null) {
            return false;
        }
        return $this->recomputeForHash((string) $row->h);
    }

    /** Recompute corroboration_count for every entry sharing $hashHex (bare 64-hex). */
    public function recomputeForHash(string $hashHex): bool
    {
        $row = $this->db->query(
            "UPDATE antibody.entry
                SET corroboration_count = sub.n, updated_at = now()
              FROM (
                  SELECT count(DISTINCT publisher) AS n
                    FROM antibody.entry
                   WHERE primary_matcher_hash = ?
                     AND status NOT IN ('slashed'::antibody.entry_status, 'expired'::antibody.entry_status)
              ) sub
              WHERE primary_matcher_hash = ?
              RETURNING antibody.entry.id",
            ['\\x' . $hashHex, '\\x' . $hashHex]
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

    private static function isZero(string $hex): bool
    {
        return $hex === '' || ltrim($hex, '0') === '';
    }
}
