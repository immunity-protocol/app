<?php

declare(strict_types=1);

namespace App\Models\Demo\Brokers;

use App\Models\Core\Broker;
use stdClass;

class CommandBroker extends Broker
{
    /**
     * Enqueue a command for the given agent. Returns the new command id so the
     * caller can hand it back to clients that want to poll for completion.
     *
     * @param array<string, mixed> $payload
     */
    public function enqueue(string $agentId, string $commandType, array $payload): int
    {
        $row = $this->selectOne(
            "INSERT INTO demo.commands (agent_id, command_type, payload)
             VALUES (?, ?, ?::jsonb)
             RETURNING id",
            [$agentId, $commandType, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]
        );
        return (int) $row->id;
    }

    public function findById(int $id): ?stdClass
    {
        return $this->selectOne(
            "SELECT id, agent_id, command_type, payload, scheduled_at,
                    picked_up_at, executed_at, result_status, result_detail
               FROM demo.commands
              WHERE id = ?",
            [$id]
        );
    }

    /**
     * Recent ADDRESS antibodies - used to populate the Card 5 (Cache replay)
     * dropdown. Returns imm_id + extracted target address (lowercased).
     *
     * @return array<int, array{imm_id: string, address: string}>
     */
    public function recentAddressAntibodies(int $limit = 20): array
    {
        $rows = $this->select(
            "SELECT imm_id, primary_matcher->>'target' AS target
               FROM antibody.entry
              WHERE type = 'address' AND status = 'active'
                AND primary_matcher ? 'target'
              ORDER BY id DESC
              LIMIT ?",
            [$limit]
        );
        $out = [];
        foreach ($rows as $r) {
            if (!is_string($r->target) || !preg_match('/^0x[0-9a-fA-F]{40}$/', $r->target)) continue;
            $out[] = ['imm_id' => $r->imm_id, 'address' => strtolower($r->target)];
        }
        return $out;
    }

    public function findAddressByImmId(string $immId): ?string
    {
        $row = $this->selectOne(
            "SELECT primary_matcher->>'target' AS target
               FROM antibody.entry
              WHERE imm_id = ? AND type = 'address'",
            [$immId]
        );
        if ($row === null || !is_string($row->target)) return null;
        return preg_match('/^0x[0-9a-fA-F]{40}$/', $row->target) ? strtolower($row->target) : null;
    }

    /**
     * Card 6 (cross-chain mirror status). Joins antibody.entry with
     * antibody.mirror so the playground can show "mirrored on Sepolia at
     * tx 0x..." per chain.
     *
     * @return array{entry: \stdClass, mirrors: array<int, \stdClass>}|null
     */
    public function mirrorStatus(string $immId): ?array
    {
        $entry = $this->selectOne(
            "SELECT id, imm_id, type, verdict, status, confidence, severity,
                    encode(publisher, 'hex') AS publisher_hex, publisher_ens,
                    created_at, primary_matcher
               FROM antibody.entry WHERE imm_id = ?",
            [$immId]
        );
        if ($entry === null) return null;
        $mirrors = $this->select(
            "SELECT chain_id, chain_name, encode(mirror_tx_hash, 'hex') AS tx_hash_hex,
                    mirrored_at, status,
                    encode(relayer_address, 'hex') AS relayer_hex
               FROM antibody.mirror
              WHERE entry_id = ?
              ORDER BY mirrored_at DESC",
            [$entry->id]
        );
        return ['entry' => $entry, 'mirrors' => $mirrors];
    }
}
