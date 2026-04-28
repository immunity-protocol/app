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
     * Recent ADDRESS antibodies — used to populate the Card 5 (Cache replay)
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
}
