<?php

declare(strict_types=1);

namespace App\Models\Domain\BlockEvent;

use stdClass;

final readonly class BlockEvent
{
    public function __construct(
        public int $id,
        public int $checkEventId,
        public int $antibodyId,
        public string $agentId,
        public string $valueProtectedUsd,
        public ?string $txHashAttempt,
        public int $chainId,
        public string $occurredAt,
    ) {
    }

    public static function fromRow(stdClass $row): self
    {
        return new self(
            id:                (int) $row->id,
            checkEventId:      (int) $row->check_event_id,
            antibodyId:        (int) $row->antibody_id,
            agentId:           $row->agent_id,
            valueProtectedUsd: (string) $row->value_protected_usd,
            txHashAttempt:     self::nullableBytea($row->tx_hash_attempt),
            chainId:           (int) $row->chain_id,
            occurredAt:        $row->occurred_at,
        );
    }

    private static function nullableBytea(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_resource($value)) {
            $c = stream_get_contents($value);
            return $c === false ? '' : $c;
        }
        return (string) $value;
    }
}
