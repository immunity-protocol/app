<?php

declare(strict_types=1);

namespace App\Models\Domain\Heartbeat;

use stdClass;

final readonly class Heartbeat
{
    public function __construct(
        public string $agentId,
        public ?string $agentEns,
        public string $agentRole,
        public string $lastSeen,
        public int $peerCount,
        public string $version,
        public mixed $metadata,
    ) {
    }

    public static function fromRow(stdClass $row): self
    {
        return new self(
            agentId:    $row->agent_id,
            agentEns:   $row->agent_ens,
            agentRole:  $row->agent_role,
            lastSeen:   $row->last_seen,
            peerCount:  (int) $row->peer_count,
            version:    $row->version,
            metadata:   $row->metadata,
        );
    }
}
