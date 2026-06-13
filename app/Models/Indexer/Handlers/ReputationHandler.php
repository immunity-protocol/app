<?php

declare(strict_types=1);

namespace App\Models\Indexer\Handlers;

use Zephyrus\Data\Database;

/**
 * Reputation contract events. The canonical score is on-chain; these mirror it
 * (display-only) onto antibody.publisher and keep denormalized counters.
 *
 *   Matured(publisher, newScore)                +1 matured_count
 *   ChallengeWon(publisher, newScore)           +1 challenges_won
 *   Slashed(publisher, newScore)                +1 slashed_count (score floors to 0 on-chain)
 *   GenesisGranted(publisher, amount, newScore) sets genesis_granted
 *
 * Each apply is guarded by an idempotent contract_event insert so the running
 * counters stay correct if the poller reprocesses a chunk.
 */
class ReputationHandler
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @param array<string,mixed> $decoded */
    public function handleMatured(array $decoded): bool
    {
        return $this->applyOnce($decoded, 'matured_count');
    }

    /** @param array<string,mixed> $decoded */
    public function handleChallengeWon(array $decoded): bool
    {
        return $this->applyOnce($decoded, 'challenges_won');
    }

    /** @param array<string,mixed> $decoded */
    public function handleSlashed(array $decoded): bool
    {
        return $this->applyOnce($decoded, 'slashed_count');
    }

    /** @param array<string,mixed> $decoded */
    public function handleGenesisGranted(array $decoded): bool
    {
        $a = $decoded['args'];
        $grant = (string) ($a['amount'] ?? '0');
        return $this->applyOnce($decoded, null, $grant);
    }

    /**
     * Upsert the publisher score and bump the given counter column (if any).
     * $genesisGrant, when set, is written to genesis_granted.
     *
     * @param array<string,mixed> $decoded
     */
    private function applyOnce(array $decoded, ?string $counterColumn, ?string $genesisGrant = null): bool
    {
        $a = $decoded['args'];
        $publisher = strtolower(self::stripHex((string) $a['publisher']));
        $score = (string) $a['newScore'];
        $txHashHex = strtolower(self::stripHex((string) ($decoded['txHash'] ?? '')));
        $name = ($decoded['contract'] ?? 'Reputation') . '.' . $decoded['event'];

        $ins = $this->db->query(
            "INSERT INTO event.contract_event (event_name, payload, block_number, tx_hash, log_index)
             VALUES (?, '{}'::jsonb, ?, ?, ?)
             ON CONFLICT (tx_hash, log_index) DO NOTHING
             RETURNING id",
            [$name, (int) ($decoded['blockNumber'] ?? 0), '\\x' . $txHashHex, (int) ($decoded['logIndex'] ?? 0)]
        );
        if ($ins->fetch(\PDO::FETCH_ASSOC) === false) {
            return false;
        }

        // Whitelisted column name (never user input) interpolated into the
        // increment; all values are bound parameters.
        $counterInsert = $counterColumn !== null ? ", $counterColumn" : '';
        $counterValue = $counterColumn !== null ? ', 1' : '';
        $counterUpdate = $counterColumn !== null
            ? ", $counterColumn = antibody.publisher.$counterColumn + 1"
            : '';

        $this->db->query(
            "INSERT INTO antibody.publisher (address, score, genesis_granted$counterInsert, first_seen_at, last_active_at)
             VALUES (?, ?::numeric(20,6), ?::numeric(20,6)$counterValue, now(), now())
             ON CONFLICT (address) DO UPDATE SET
                score           = EXCLUDED.score,
                genesis_granted = GREATEST(antibody.publisher.genesis_granted, EXCLUDED.genesis_granted),
                last_active_at  = now()$counterUpdate",
            ['\\x' . $publisher, $score, $genesisGrant ?? '0']
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
