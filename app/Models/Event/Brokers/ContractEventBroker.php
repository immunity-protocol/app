<?php

declare(strict_types=1);

namespace App\Models\Event\Brokers;

use App\Models\Core\Broker;
use stdClass;

class ContractEventBroker extends Broker
{
    /**
     * Low-signal internal/mechanical events hidden from the dashboard feed —
     * reputation bootstrap, bond/escrow/deposit plumbing, admin setup. The feed
     * keeps the story: publishes, checks, blocks (Matched), maturations,
     * challenges/resolutions, CRE verdicts, slashes, cross-chain mirrors.
     * Static list (no user input) so it's safe to inline.
     */
    private const FEED_HIDDEN = "'Reputation.GenesisGranted','Reputation.Matured',"
        . "'Reputation.ChallengeWon','Registry.BondLocked','Registry.FeesEscrowed',"
        . "'Registry.Deposited','RelayerSet','AdminTransferred'";

    /**
     * @return stdClass[]
     */
    public function findRecent(int $limit): array
    {
        return $this->select(
            "SELECT * FROM event.contract_event ORDER BY occurred_at DESC LIMIT ?",
            [$limit]
        );
    }

    /**
     * Recent on-chain events for the dashboard event feed, newest first. The
     * tx_hash is returned as a 0x-hex string and the jsonb payload is decoded
     * to an associative array so the view can read args directly. Ordered by
     * block then log index (a stable on-chain order) and finally id, so events
     * ingested in the same backfill tick (shared occurred_at) still sort right.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findRecentForFeed(int $limit): array
    {
        $rows = $this->select(
            "SELECT ce.id, ce.event_name, ce.payload, ce.block_number,
                    '0x' || encode(ce.tx_hash, 'hex') AS tx_hash,
                    ce.log_index, ce.occurred_at,
                    ae.imm_id AS imm_id, ae.redacted_reasoning AS reasoning,
                    coalesce(ce.payload->>'agent', ce.payload->>'publisher') AS actor_address,
                    ap.ens AS actor_ens
               FROM event.contract_event ce
               LEFT JOIN antibody.entry ae
                 ON ae.keccak_id = decode(substr(coalesce(ce.payload->>'keccakId', ce.payload->>'antibodyId'), 3), 'hex')
               LEFT JOIN antibody.publisher ap
                 ON ap.address = decode(substr(coalesce(ce.payload->>'agent', ce.payload->>'publisher'), 3), 'hex')
              WHERE ce.event_name NOT IN (" . self::FEED_HIDDEN . ")
              ORDER BY ce.block_number DESC, ce.log_index DESC, ce.id DESC
              LIMIT ?",
            [$limit]
        );
        return array_map([self::class, 'mapFeedRow'], $rows);
    }

    /**
     * Older events strictly before the (block_number, log_index, id) keyset
     * cursor, for the dashboard's infinite scroll. Same shape + order as
     * findRecentForFeed so the client renders them identically.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findOlderForFeed(int $beforeBlock, int $beforeLogIndex, int $beforeId, int $limit): array
    {
        $rows = $this->select(
            "SELECT ce.id, ce.event_name, ce.payload, ce.block_number,
                    '0x' || encode(ce.tx_hash, 'hex') AS tx_hash,
                    ce.log_index, ce.occurred_at,
                    ae.imm_id AS imm_id, ae.redacted_reasoning AS reasoning,
                    coalesce(ce.payload->>'agent', ce.payload->>'publisher') AS actor_address,
                    ap.ens AS actor_ens
               FROM event.contract_event ce
               LEFT JOIN antibody.entry ae
                 ON ae.keccak_id = decode(substr(coalesce(ce.payload->>'keccakId', ce.payload->>'antibodyId'), 3), 'hex')
               LEFT JOIN antibody.publisher ap
                 ON ap.address = decode(substr(coalesce(ce.payload->>'agent', ce.payload->>'publisher'), 3), 'hex')
              WHERE (ce.block_number, ce.log_index, ce.id) < (?, ?, ?)
                AND ce.event_name NOT IN (" . self::FEED_HIDDEN . ")
              ORDER BY ce.block_number DESC, ce.log_index DESC, ce.id DESC
              LIMIT ?",
            [$beforeBlock, $beforeLogIndex, $beforeId, $limit]
        );
        return array_map([self::class, 'mapFeedRow'], $rows);
    }

    /** @return array<string, mixed> */
    private static function mapFeedRow(stdClass $r): array
    {
        $payload = is_string($r->payload) ? json_decode($r->payload, true) : (array) $r->payload;
        return [
            'id'           => (int) $r->id,
            'event_name'   => (string) $r->event_name,
            'payload'      => is_array($payload) ? $payload : [],
            'block_number' => (int) $r->block_number,
            'tx_hash'      => (string) $r->tx_hash,
            'log_index'    => (int) $r->log_index,
            'occurred_at'  => (string) $r->occurred_at,
            'imm_id'       => isset($r->imm_id) ? (string) $r->imm_id : null,
            'reasoning'    => isset($r->reasoning) ? (string) $r->reasoning : null,
            'actor_address'=> isset($r->actor_address) ? (string) $r->actor_address : null,
            'actor_ens'    => isset($r->actor_ens) ? (string) $r->actor_ens : null,
        ];
    }

    public function countByName(string $eventName): int
    {
        return (int) $this->selectValue(
            "SELECT count(*) FROM event.contract_event WHERE event_name = ?",
            [$eventName]
        );
    }

    /**
     * Insert one row. Returns the new id, or null if the (tx_hash, log_index)
     * pair was already recorded (idempotent backfill).
     *
     * @param array<string, mixed> $payload arbitrary jsonb-able event fields
     */
    public function insert(
        string $eventName,
        array $payload,
        int $blockNumber,
        string $txHashBytes,
        int $logIndex,
        string $occurredAt
    ): ?int {
        $row = $this->selectOne(
            "INSERT INTO event.contract_event
                (event_name, payload, block_number, tx_hash, log_index, occurred_at)
             VALUES (?, ?::jsonb, ?, ?, ?, ?)
             ON CONFLICT (tx_hash, log_index) DO NOTHING
             RETURNING id",
            [
                $eventName,
                json_encode($payload, JSON_UNESCAPED_SLASHES),
                $blockNumber,
                $txHashBytes,
                $logIndex,
                $occurredAt,
            ]
        );
        return $row !== null ? (int) $row->id : null;
    }
}
