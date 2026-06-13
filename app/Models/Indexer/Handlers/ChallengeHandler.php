<?php

declare(strict_types=1);

namespace App\Models\Indexer\Handlers;

use Zephyrus\Data\Database;

/**
 * Challenge / jury ingestion (Phase 2). Maintains antibody.challenge from the
 * ChallengeManager + CREVerdictReceiver events and reflects the challenge state
 * onto antibody.entry from the Registry's challenge events.
 *
 *   Registry.ChallengeOpened(keccakId)                          entry → challenged
 *   Registry.ChallengeTimedOut(keccakId, publisher)             entry → active (stands)
 *   ChallengeManager.VerdictRequested(antibodyId, evidenceCid)  open LAYER1_PENDING
 *   ChallengeManager.Escalated(antibodyId)                      → LAYER2_ESCALATED
 *   ChallengeManager.Resolved(antibodyId, invalid, challenger, winnerPayout, jurorFee, treasuryAmount)
 *   CREVerdictReceiver.VerdictReceived(antibodyId, invalidVotes, validVotes)  Layer-1 tally
 *
 * Upserts on keccak_id so out-of-order backfill (a verdict before its request)
 * still converges.
 */
class ChallengeHandler
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @param array<string,mixed> $decoded Registry.ChallengeOpened */
    public function handleChallengeOpened(array $decoded): bool
    {
        return $this->setEntryStatus($decoded, 'challenged', "status <> 'slashed'::antibody.entry_status");
    }

    /** @param array<string,mixed> $decoded Registry.ChallengeTimedOut */
    public function handleChallengeTimedOut(array $decoded): bool
    {
        return $this->setEntryStatus($decoded, 'active', "status = 'challenged'::antibody.entry_status");
    }

    /** @param array<string,mixed> $decoded ChallengeManager.VerdictRequested */
    public function handleVerdictRequested(array $decoded): bool
    {
        $keccak = self::keccak($decoded);
        $evidence = self::stripHex((string) ($decoded['args']['evidenceCid'] ?? ''));
        $evidenceBytea = self::isZero($evidence) ? null : '\\x' . $evidence;
        $this->db->query(
            "INSERT INTO antibody.challenge (keccak_id, evidence_cid, status, opened_at)
             VALUES (?, ?, 'LAYER1_PENDING', now())
             ON CONFLICT (keccak_id) DO UPDATE SET
                evidence_cid = COALESCE(EXCLUDED.evidence_cid, antibody.challenge.evidence_cid),
                status = CASE WHEN antibody.challenge.status = 'RESOLVED' THEN antibody.challenge.status ELSE 'LAYER1_PENDING' END",
            ['\\x' . $keccak, $evidenceBytea]
        );
        return true;
    }

    /** @param array<string,mixed> $decoded ChallengeManager.Escalated */
    public function handleEscalated(array $decoded): bool
    {
        $keccak = self::keccak($decoded);
        $this->db->query(
            "INSERT INTO antibody.challenge (keccak_id, status, escalated_at, opened_at)
             VALUES (?, 'LAYER2_ESCALATED', now(), now())
             ON CONFLICT (keccak_id) DO UPDATE SET
                status = 'LAYER2_ESCALATED', escalated_at = now()",
            ['\\x' . $keccak]
        );
        return true;
    }

    /** @param array<string,mixed> $decoded ChallengeManager.Resolved */
    public function handleResolved(array $decoded): bool
    {
        $a = $decoded['args'];
        $keccak = self::keccak($decoded);
        $challenger = self::stripHex((string) ($a['challenger'] ?? ''));
        $challengerBytea = self::isZero($challenger) ? null : '\\x' . $challenger;
        $this->db->query(
            "INSERT INTO antibody.challenge
                (keccak_id, challenger, status, is_invalid, winner_payout, juror_fee, treasury_amount, resolved_at, opened_at)
             VALUES (?, ?, 'RESOLVED', ?, ?::numeric(20,6), ?::numeric(20,6), ?::numeric(20,6), now(), now())
             ON CONFLICT (keccak_id) DO UPDATE SET
                challenger      = COALESCE(EXCLUDED.challenger, antibody.challenge.challenger),
                status          = 'RESOLVED',
                is_invalid      = EXCLUDED.is_invalid,
                winner_payout   = EXCLUDED.winner_payout,
                juror_fee       = EXCLUDED.juror_fee,
                treasury_amount = EXCLUDED.treasury_amount,
                resolved_at     = now()",
            [
                '\\x' . $keccak, $challengerBytea, !empty($a['invalid']) ? 't' : 'f',
                self::weiToUsdc((string) ($a['winnerPayout'] ?? '0')),
                self::weiToUsdc((string) ($a['jurorFee'] ?? '0')),
                self::weiToUsdc((string) ($a['treasuryAmount'] ?? '0')),
            ]
        );
        return true;
    }

    /** @param array<string,mixed> $decoded CREVerdictReceiver.VerdictReceived */
    public function handleVerdictReceived(array $decoded): bool
    {
        $a = $decoded['args'];
        $keccak = self::keccak($decoded);
        $this->db->query(
            "INSERT INTO antibody.challenge (keccak_id, invalid_votes, valid_votes, status, opened_at)
             VALUES (?, ?, ?, 'LAYER1_PENDING', now())
             ON CONFLICT (keccak_id) DO UPDATE SET
                invalid_votes = EXCLUDED.invalid_votes,
                valid_votes   = EXCLUDED.valid_votes",
            ['\\x' . $keccak, (int) ($a['invalidVotes'] ?? 0), (int) ($a['validVotes'] ?? 0)]
        );
        return true;
    }

    /** @param array<string,mixed> $decoded */
    private function setEntryStatus(array $decoded, string $status, string $guard): bool
    {
        $keccak = self::keccak($decoded);
        $row = $this->db->query(
            "UPDATE antibody.entry
                SET status = ?::antibody.entry_status, updated_at = now()
              WHERE keccak_id = ? AND $guard
              RETURNING id",
            [$status, '\\x' . $keccak]
        );
        return $row->fetch(\PDO::FETCH_ASSOC) !== false;
    }

    /** @param array<string,mixed> $decoded */
    private static function keccak(array $decoded): string
    {
        $a = $decoded['args'];
        $id = (string) ($a['antibodyId'] ?? $a['keccakId'] ?? '');
        return strtolower(self::stripHex($id));
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

    private static function weiToUsdc(string $value): string
    {
        if (function_exists('bcdiv')) {
            return bcdiv($value, '1000000', 6);
        }
        return number_format(((float) $value) / 1_000_000, 6, '.', '');
    }
}
