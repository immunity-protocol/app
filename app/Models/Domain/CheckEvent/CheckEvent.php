<?php

declare(strict_types=1);

namespace App\Models\Domain\CheckEvent;

use stdClass;

final readonly class CheckEvent
{
    public function __construct(
        public int $id,
        public string $agentId,
        public string $txKind,
        public int $chainId,
        public string $decision,
        public ?int $confidence,
        public ?int $matchedAntibodyId,
        public bool $cacheHit,
        public bool $teeUsed,
        public ?string $valueAtRiskUsd,
        public string $occurredAt,
    ) {
    }

    public static function fromRow(stdClass $row): self
    {
        return new self(
            id:                (int) $row->id,
            agentId:           $row->agent_id,
            txKind:            $row->tx_kind,
            chainId:           (int) $row->chain_id,
            decision:          $row->decision,
            confidence:        $row->confidence === null ? null : (int) $row->confidence,
            matchedAntibodyId: $row->matched_antibody_id === null ? null : (int) $row->matched_antibody_id,
            cacheHit:          (bool) $row->cache_hit,
            teeUsed:           (bool) $row->tee_used,
            valueAtRiskUsd:    $row->value_at_risk_usd === null ? null : (string) $row->value_at_risk_usd,
            occurredAt:        $row->occurred_at,
        );
    }
}
