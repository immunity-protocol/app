<?php

declare(strict_types=1);

namespace App\Models\Domain\NetworkStat;

use stdClass;

final readonly class NetworkStat
{
    public function __construct(
        public int $id,
        public string $metric,
        public string $value,
        public string $capturedAt,
        public mixed $metadata,
    ) {
    }

    public static function fromRow(stdClass $row): self
    {
        return new self(
            id:         (int) $row->id,
            metric:     $row->metric,
            value:      (string) $row->value,
            capturedAt: $row->captured_at,
            metadata:   $row->metadata,
        );
    }
}
