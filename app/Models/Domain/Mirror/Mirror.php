<?php

declare(strict_types=1);

namespace App\Models\Domain\Mirror;

use stdClass;

final readonly class Mirror
{
    public function __construct(
        public int $id,
        public int $antibodyId,
        public int $chainId,
        public string $chainName,
        public string $mirrorTxHash,
        public string $mirroredAt,
        public string $status,
        public string $relayerAddress,
    ) {
    }

    public static function fromRow(stdClass $row): self
    {
        return new self(
            id:              (int) $row->id,
            antibodyId:      (int) $row->antibody_id,
            chainId:         (int) $row->chain_id,
            chainName:       $row->chain_name,
            mirrorTxHash:    self::bytea($row->mirror_tx_hash),
            mirroredAt:      $row->mirrored_at,
            status:          $row->status,
            relayerAddress:  self::bytea($row->relayer_address),
        );
    }

    private static function bytea(mixed $value): string
    {
        if (is_resource($value)) {
            $c = stream_get_contents($value);
            return $c === false ? '' : $c;
        }
        return (string) $value;
    }
}
