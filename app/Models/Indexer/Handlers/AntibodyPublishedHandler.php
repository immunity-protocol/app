<?php

declare(strict_types=1);

namespace App\Models\Indexer\Handlers;

use App\Models\Indexer\Brokers\HydrationQueueBroker;
use App\Models\Mirror\MirrorEnvelopeBuffer;
use Zephyrus\Data\Database;

/**
 * AntibodyPublished(
 *   indexed bytes32 keccakId, indexed uint32 immSeq, indexed address publisher,
 *   uint8 abType, uint8 flavor, uint8 verdict, uint8 severity, uint8 confidence,
 *   address reviewer, bytes32 primaryMatcherHash, bytes32 evidenceCid,
 *   bytes32 contextHash, bytes32 embeddingHash, bytes32 attestation,
 *   uint256 stake, uint64 stakeLockUntil, uint64 expiresAt, uint64 createdAt,
 *   bool isSeeded
 * )
 *
 * Inserts a row into antibody.entry (idempotent on keccak_id), upserts the
 * publisher aggregate, and enqueues a 0G Storage hydration job.
 */
class AntibodyPublishedHandler
{
    private const ENTRY_TYPES = [
        0 => 'address',
        1 => 'call_pattern',
        2 => 'bytecode',
        3 => 'graph',
        4 => 'semantic',
    ];

    private const VERDICTS = [
        0 => 'malicious',
        1 => 'suspicious',
    ];

    private const SEMANTIC_FLAVORS = [
        0 => 'counterparty',
        1 => 'manipulation',
        2 => 'prompt_injection',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly HydrationQueueBroker $queue,
        private readonly ?MirrorEnvelopeBuffer $envelopeBuffer = null,
    ) {
    }

    /**
     * @param array{event:string,args:array<string,mixed>,blockNumber:int,txHash:string,logIndex:int,address:string} $decoded
     * @return bool true if a new row was inserted, false if duplicate
     */
    public function handle(array $decoded): bool
    {
        $a = $decoded['args'];

        $keccakIdHex = self::stripHex((string) $a['keccakId']);
        $publisherHex = self::stripHex((string) $a['publisher']);
        $contextHashHex = self::stripHex((string) $a['contextHash']);
        $evidenceCidHex = self::stripHex((string) $a['evidenceCid']);
        $embeddingHashHex = self::stripHex((string) $a['embeddingHash']);
        $attestationHex = self::stripHex((string) $a['attestation']);

        $keccakIdBytea = '\\x' . $keccakIdHex;
        $publisherBytea = '\\x' . $publisherHex;
        $contextHashBytea = '\\x' . $contextHashHex;
        $evidenceCidBytea = '\\x' . $evidenceCidHex;
        $embeddingHashBytea = '\\x' . $embeddingHashHex;
        $attestationBytea = '\\x' . $attestationHex;

        $abType = (int) $a['abType'];
        $type = self::ENTRY_TYPES[$abType] ?? 'address';
        $verdict = self::VERDICTS[(int) $a['verdict']] ?? 'malicious';
        $flavor = $type === 'semantic'
            ? (self::SEMANTIC_FLAVORS[(int) $a['flavor']] ?? null)
            : null;

        $createdAtSec = (int) $a['createdAt'];
        $expiresAtSec = (int) $a['expiresAt'];
        $stakeLockUntilSec = (int) $a['stakeLockUntil'];

        $createdAtIso = gmdate('Y-m-d\TH:i:s\Z', $createdAtSec);
        $year = gmdate('Y', $createdAtSec);
        $immId = sprintf('IMM-%s-%04d', $year, (int) $a['immSeq']);

        $stakeWei = (string) $a['stake'];
        $stakeUsdc = self::weiToUsdc($stakeWei);

        $row = $this->db->query(
            <<<'SQL'
            INSERT INTO antibody.entry (
                keccak_id, imm_id, type, flavor, verdict,
                confidence, severity, status,
                primary_matcher, secondary_matchers,
                context_hash, evidence_cid, embedding_hash, embedding_cid,
                stake_lock_until, expires_at, publisher, publisher_ens,
                stake_amount, attestation, seed_source, redacted_reasoning,
                created_at, updated_at
            )
            VALUES (
                ?, ?, ?::antibody.entry_type, ?, ?::antibody.entry_verdict,
                ?, ?, 'active'::antibody.entry_status,
                '{}'::jsonb, '[]'::jsonb,
                ?, ?, ?, NULL,
                to_timestamp(?), CASE WHEN ? > 0 THEN to_timestamp(?) ELSE NULL END,
                ?, NULL,
                ?, ?, CASE WHEN ? THEN 'admin' ELSE NULL END, NULL,
                to_timestamp(?), to_timestamp(?)
            )
            ON CONFLICT (keccak_id) DO NOTHING
            RETURNING id
            SQL,
            [
                $keccakIdBytea, $immId, $type, $flavor, $verdict,
                (int) $a['confidence'], (int) $a['severity'],
                $contextHashBytea, $evidenceCidBytea, $embeddingHashBytea,
                $stakeLockUntilSec,
                $expiresAtSec, $expiresAtSec,
                $publisherBytea,
                $stakeUsdc, $attestationBytea,
                (bool) $a['isSeeded'] ? 't' : 'f',
                $createdAtSec, $createdAtSec,
            ]
        );
        $inserted = $row->fetch(\PDO::FETCH_ASSOC) !== false;

        if ($inserted) {
            // Publisher aggregate: only adjust when this is a new entry (avoid
            // double-counting on event replays during backfill).
            $this->db->query(
                <<<'SQL'
                INSERT INTO antibody.publisher (address, antibodies_published, total_staked_usdc, first_seen_at, last_active_at)
                VALUES (?, 1, ?, to_timestamp(?), to_timestamp(?))
                ON CONFLICT (address) DO UPDATE SET
                    antibodies_published = antibody.publisher.antibodies_published + 1,
                    total_staked_usdc    = antibody.publisher.total_staked_usdc + EXCLUDED.total_staked_usdc,
                    last_active_at       = GREATEST(antibody.publisher.last_active_at, EXCLUDED.last_active_at)
                SQL,
                [$publisherBytea, $stakeUsdc, $createdAtSec, $createdAtSec]
            );
        }

        // Insert an activity row so the dashboard's live feed reflects it.
        if ($inserted) {
            $this->db->query(
                <<<'SQL'
                INSERT INTO event.activity (event_type, entry_id, payload, actor, occurred_at)
                SELECT 'published'::event.activity_type, e.id,
                       jsonb_build_object('imm_id', e.imm_id, 'type', e.type::text),
                       coalesce(p.ens, '0x' || encode(e.publisher, 'hex')),
                       e.created_at
                  FROM antibody.entry e
                  LEFT JOIN antibody.publisher p ON p.address = e.publisher
                 WHERE e.keccak_id = ?
                 LIMIT 1
                SQL,
                [$keccakIdBytea]
            );
        }

        // Enqueue 0G Storage hydration unless evidenceCid is empty.
        if (!self::isZeroHex($evidenceCidHex)) {
            $this->queue->enqueueHex($keccakIdHex, $evidenceCidHex);
        }

        // Stash the full event args for the relayer; the matching auxiliary
        // event (AddressBlocked / CallPatternBlocked / ...) fires later in
        // the same tx and will drain this buffer to enqueue mirror jobs.
        if ($inserted && $this->envelopeBuffer !== null) {
            $this->envelopeBuffer->stash($keccakIdHex, $a);
        }

        unset($decoded['blockNumber'], $decoded['logIndex']);
        return $inserted;
    }

    private static function stripHex(string $hex): string
    {
        if (str_starts_with($hex, '0x') || str_starts_with($hex, '0X')) {
            $hex = substr($hex, 2);
        }
        return strtolower($hex);
    }

    private static function isZeroHex(string $hex): bool
    {
        return $hex === '' || ltrim($hex, '0') === '';
    }

    /**
     * Stake amounts are quoted in USDC base units (6 decimals) per the SDK.
     * We store them as numeric(20,6); divide by 1e6.
     */
    private static function weiToUsdc(string $value): string
    {
        if (function_exists('bcdiv')) {
            return bcdiv($value, '1000000', 6);
        }
        return number_format(((float) $value) / 1_000_000, 6, '.', '');
    }
}
