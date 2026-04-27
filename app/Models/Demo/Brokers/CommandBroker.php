<?php

declare(strict_types=1);

namespace App\Models\Demo\Brokers;

use App\Models\Core\Broker;

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
}
