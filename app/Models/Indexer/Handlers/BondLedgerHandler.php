<?php

declare(strict_types=1);

namespace App\Models\Indexer\Handlers;

use Zephyrus\Data\Database;

/**
 * Bond/fee-escrow ledger for the bond model. These events carry the running
 * economic state of an antibody entry:
 *
 *   BondLocked(keccakId, publisher, amount)      bond locked at publish
 *   BondReleased(keccakId, publisher, amount)    bond returned on expire/retire
 *   FeesEscrowed(keccakId, publisher, amount)    probation match share held
 *   FeesReleased(keccakId, publisher, amount)    escrow paid to publisher
 *   FeesClawedBack(keccakId, publisher, amount)  escrow returned to treasury
 *
 * Escrow deltas accumulate, so every apply is guarded by an idempotent
 * event.contract_event insert (unique on tx_hash, log_index) to stay correct
 * if the poller reprocesses a chunk after a crash.
 */
class BondLedgerHandler
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @param array<string,mixed> $decoded */
    public function handleBondLocked(array $decoded): bool
    {
        return $this->applyOnce($decoded, function (string $keccak, string $amount): void {
            $this->db->query(
                "UPDATE antibody.entry SET bond_amount = ?::numeric(20,6), updated_at = now() WHERE keccak_id = ?",
                [$amount, '\\x' . $keccak]
            );
        });
    }

    /** @param array<string,mixed> $decoded */
    public function handleBondReleased(array $decoded): bool
    {
        return $this->applyOnce($decoded, function (string $keccak, string $amount): void {
            $this->db->query(
                "UPDATE antibody.entry SET bond_amount = 0, updated_at = now() WHERE keccak_id = ?",
                ['\\x' . $keccak]
            );
        });
    }

    /** @param array<string,mixed> $decoded */
    public function handleFeesEscrowed(array $decoded): bool
    {
        return $this->applyOnce($decoded, function (string $keccak, string $amount): void {
            $this->db->query(
                "UPDATE antibody.entry
                    SET escrowed_fees = escrowed_fees + ?::numeric(20,6), updated_at = now()
                  WHERE keccak_id = ?",
                [$amount, '\\x' . $keccak]
            );
        });
    }

    /** @param array<string,mixed> $decoded */
    public function handleFeesReleased(array $decoded): bool
    {
        return $this->applyOnce($decoded, function (string $keccak, string $amount, string $publisher): void {
            $this->db->query(
                "UPDATE antibody.entry
                    SET escrowed_fees = GREATEST(escrowed_fees - ?::numeric(20,6), 0), updated_at = now()
                  WHERE keccak_id = ?",
                [$amount, '\\x' . $keccak]
            );
            // Released escrow is paid to the publisher — book it as earnings.
            $this->db->query(
                "UPDATE antibody.publisher
                    SET total_earned_usdc = total_earned_usdc + ?::numeric(20,6), last_active_at = now()
                  WHERE address = ?",
                [$amount, '\\x' . $publisher]
            );
        });
    }

    /** @param array<string,mixed> $decoded */
    public function handleFeesClawedBack(array $decoded): bool
    {
        return $this->applyOnce($decoded, function (string $keccak, string $amount): void {
            $this->db->query(
                "UPDATE antibody.entry
                    SET escrowed_fees = GREATEST(escrowed_fees - ?::numeric(20,6), 0), updated_at = now()
                  WHERE keccak_id = ?",
                [$amount, '\\x' . $keccak]
            );
        });
    }

    /**
     * Guard a ledger delta behind an idempotent contract_event insert, then run
     * $apply($keccakHex, $amountUsdc, $publisherHex). Returns false on replay.
     *
     * @param array<string,mixed> $decoded
     */
    private function applyOnce(array $decoded, callable $apply): bool
    {
        $a = $decoded['args'];
        $keccak = strtolower(self::stripHex((string) $a['keccakId']));
        $publisher = strtolower(self::stripHex((string) ($a['publisher'] ?? '')));
        $amount = self::weiToUsdc((string) $a['amount']);
        $txHashHex = strtolower(self::stripHex((string) ($decoded['txHash'] ?? '')));
        $name = ($decoded['contract'] ?? 'Registry') . '.' . $decoded['event'];

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

        $apply($keccak, $amount, $publisher);
        return true;
    }

    private static function stripHex(string $hex): string
    {
        if (str_starts_with($hex, '0x') || str_starts_with($hex, '0X')) {
            return substr($hex, 2);
        }
        return $hex;
    }

    private static function weiToUsdc(string $value): string
    {
        if (function_exists('bcdiv')) {
            return bcdiv($value, '1000000', 6);
        }
        return number_format(((float) $value) / 1_000_000, 6, '.', '');
    }
}
