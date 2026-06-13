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
