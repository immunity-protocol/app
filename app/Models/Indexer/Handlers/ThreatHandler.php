<?php

declare(strict_types=1);

namespace App\Models\Indexer\Handlers;

use Zephyrus\Data\Database;

/**
 * Threat assignment. Corroboration means several DISTINCT publishers each mint
 * their own antibody (own keccak_id) for the SAME primary_matcher_hash. The
 * THREAT is that shared matcher — the CVE-style registry unit. Each per-publisher
 * antibody is a corroborating source, not a headline row.
 *
 * On the first antibody seen for a matcher hash, assign a stable, sequential,
 * CVE-style identifier: IMM-T-YYYY-NNNN where NNNN is the threat's serial
 * (zero-padded to 4) and YYYY is the year of first sighting. Corroborating
 * antibodies for the same matcher link to the existing threat — the upsert is
 * keyed on matcher_hash and DO NOTHING on conflict, so the serial is consumed
 * exactly once and re-runs over the same events never renumber a threat.
 *
 * Mirrors the AntibodyPublishedHandler's IMM-YYYY-NNNN scheme but at the matcher
 * grain; the `-T-` infix disambiguates a threat id from a per-antibody imm_id.
 */
class ThreatHandler
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array<string,mixed> $decoded Registry.Published — the matcher hash
     *        and createdAt are in the event args.
     * @return bool true when a NEW threat was assigned, false when this matcher
     *         already had one (a corroborator joining an existing threat).
     */
    public function handlePublished(array $decoded): bool
    {
        $a = $decoded['args'] ?? [];
        $hash = strtolower(self::stripHex((string) ($a['primaryMatcherHash'] ?? '')));
        if (self::isZero($hash)) {
            return false;
        }
        $createdAtSec = (int) ($a['createdAt'] ?? time());

        return $this->assign($hash, $createdAtSec);
    }

    /**
     * Idempotent: claim a threat_seq for $hashHex (bare 64-hex) if unseen, then
     * stamp the CVE-style threat_id from the assigned serial. Returns true only
     * when this call created the threat.
     */
    public function assign(string $hashHex, int $firstSeenSec): bool
    {
        $bytea = '\\x' . $hashHex;
        $year = gmdate('Y', $firstSeenSec);

        // Insert claims the serial (DO NOTHING keeps it stable on replay), then
        // stamp threat_id from the assigned seq. threat_id starts as the seq's
        // own value via a deferred UPDATE so it stays in lockstep with the serial.
        $row = $this->db->query(
            <<<'SQL'
            INSERT INTO antibody.threat (threat_id, matcher_hash, first_seen_at, created_at)
            VALUES ('pending', ?, to_timestamp(?), to_timestamp(?))
            ON CONFLICT (matcher_hash) DO NOTHING
            RETURNING threat_seq
            SQL,
            [$bytea, $firstSeenSec, $firstSeenSec]
        );
        $fetched = $row->fetch(\PDO::FETCH_OBJ);
        if ($fetched === false) {
            // Matcher already had a threat — a corroborator joining. No-op.
            return false;
        }

        $seq = (int) $fetched->threat_seq;
        $threatId = sprintf('IMM-T-%s-%04d', $year, $seq);
        $this->db->query(
            "UPDATE antibody.threat SET threat_id = ? WHERE threat_seq = ?",
            [$threatId, $seq]
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

    private static function isZero(string $hex): bool
    {
        return $hex === '' || ltrim($hex, '0') === '';
    }
}
