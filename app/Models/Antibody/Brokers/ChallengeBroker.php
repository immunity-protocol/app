<?php

declare(strict_types=1);

namespace App\Models\Antibody\Brokers;

use App\Models\Core\Broker;
use stdClass;

/**
 * Reads the challenge game's on-chain history (antibody.challenge), keyed by
 * the challenged antibody's keccak_id. Used by the antibody/threat detail to
 * surface "this antibody was challenged — and here's how the jury ruled".
 */
class ChallengeBroker extends Broker
{
    /**
     * All challenges whose keccak_id is in $hexIds (0x-prefixed or bare hex),
     * newest first. Returns a view-friendly shape with the resolution decoded.
     *
     * @param string[] $hexIds
     * @return array<int, array<string, mixed>>
     */
    public function findByKeccakIds(array $hexIds): array
    {
        $bare = [];
        foreach ($hexIds as $h) {
            $h = strtolower((string) $h);
            if (str_starts_with($h, '0x')) {
                $h = substr($h, 2);
            }
            if (preg_match('/^[0-9a-f]{64}$/', $h)) {
                $bare[$h] = true;
            }
        }
        if ($bare === []) {
            return [];
        }
        $ids = array_keys($bare);
        $placeholders = implode(', ', array_fill(0, count($ids), "decode(?, 'hex')"));
        $rows = $this->select(
            "SELECT '0x' || encode(keccak_id, 'hex') AS keccak_id,
                    CASE WHEN challenger IS NULL THEN NULL
                         ELSE '0x' || encode(challenger, 'hex') END AS challenger,
                    bond, status, invalid_votes, valid_votes, is_invalid,
                    winner_payout, juror_fee, treasury_amount,
                    opened_at, escalated_at, resolved_at
               FROM antibody.challenge
              WHERE keccak_id IN ($placeholders)
              ORDER BY opened_at DESC",
            $ids
        );
        return array_map([self::class, 'mapRow'], $rows);
    }

    public function countAll(): int
    {
        return (int) $this->selectValue("SELECT count(*) FROM antibody.challenge");
    }

    public function countOpen(): int
    {
        return (int) $this->selectValue(
            "SELECT count(*) FROM antibody.challenge WHERE status <> 'RESOLVED'"
        );
    }

    /** Resolved challenges where the antibody was struck down as a false positive. */
    public function countStruck(): int
    {
        return (int) $this->selectValue(
            "SELECT count(*) FROM antibody.challenge WHERE status = 'RESOLVED' AND is_invalid = true"
        );
    }

    /**
     * Recent challenges with the challenged antibody's context (imm_id, target,
     * publisher) and the challenger identity — the "watch the immune system
     * fight" feed. Open challenges first, then most-recently resolved.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findRecent(int $limit = 40): array
    {
        $rows = $this->select(
            "SELECT '0x' || encode(c.keccak_id, 'hex')                          AS keccak_id,
                    CASE WHEN c.challenger IS NULL THEN NULL
                         ELSE '0x' || encode(c.challenger, 'hex') END           AS challenger,
                    cp.ens                                                       AS challenger_ens,
                    c.bond, c.status, c.invalid_votes, c.valid_votes, c.is_invalid,
                    c.winner_payout, c.juror_fee, c.treasury_amount,
                    c.opened_at, c.escalated_at, c.resolved_at,
                    e.imm_id,
                    e.primary_matcher->>'target'                                 AS target,
                    e.prominence_tier,
                    '0x' || encode(e.publisher, 'hex')                           AS publisher,
                    pp.ens                                                        AS publisher_ens
               FROM antibody.challenge c
          LEFT JOIN antibody.entry e     ON e.keccak_id = c.keccak_id
          LEFT JOIN antibody.publisher pp ON pp.address = e.publisher
          LEFT JOIN antibody.publisher cp ON cp.address = c.challenger
           ORDER BY (c.status = 'RESOLVED'), coalesce(c.resolved_at, c.opened_at) DESC
              LIMIT ?",
            [$limit]
        );
        return array_map([self::class, 'mapRecentRow'], $rows);
    }

    /** @return array<string, mixed> */
    private static function mapRecentRow(stdClass $r): array
    {
        $base = self::mapRow($r);
        $base['imm_id']          = $r->imm_id !== null ? (string) $r->imm_id : null;
        $base['target']          = $r->target;
        $base['prominence_tier'] = (int) ($r->prominence_tier ?? 0);
        $base['publisher']       = $r->publisher !== null ? (string) $r->publisher : null;
        $base['publisher_ens']   = $r->publisher_ens;
        $base['challenger_ens']  = $r->challenger_ens;
        return $base;
    }

    /** @return array<string, mixed> */
    private static function mapRow(stdClass $r): array
    {
        $resolved = $r->resolved_at !== null;
        // is_invalid TRUE  → antibody was a false positive → STRUCK (challenger won)
        // is_invalid FALSE → antibody upheld as a real threat → UPHELD (challenger lost)
        $outcome = !$resolved
            ? 'pending'
            : ((bool) $r->is_invalid ? 'struck' : 'upheld');
        return [
            'keccak_id'       => (string) $r->keccak_id,
            'challenger'      => $r->challenger !== null ? (string) $r->challenger : null,
            'bond'            => $r->bond !== null ? (float) $r->bond : null,
            'status'          => (string) $r->status,
            'invalid_votes'   => (int) $r->invalid_votes,
            'valid_votes'     => (int) $r->valid_votes,
            'outcome'         => $outcome,
            'winner_payout'   => $r->winner_payout !== null ? (float) $r->winner_payout : null,
            'treasury_amount' => $r->treasury_amount !== null ? (float) $r->treasury_amount : null,
            'opened_at'       => (string) $r->opened_at,
            'resolved_at'     => $resolved ? (string) $r->resolved_at : null,
        ];
    }
}
