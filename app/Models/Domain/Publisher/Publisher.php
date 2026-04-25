<?php

declare(strict_types=1);

namespace App\Models\Domain\Publisher;

use stdClass;

final readonly class Publisher
{
    public function __construct(
        public string $address,
        public ?string $ens,
        public int $antibodiesPublished,
        public int $successfulBlocks,
        public string $totalEarnedUsdc,
        public string $totalStakedUsdc,
        public int $successfulChallengesWon,
        public int $challengesLost,
        public string $firstSeenAt,
        public string $lastActiveAt,
    ) {
    }

    public static function fromRow(stdClass $row): self
    {
        return new self(
            address:                 self::bytea($row->address),
            ens:                     $row->ens,
            antibodiesPublished:     (int) $row->antibodies_published,
            successfulBlocks:        (int) $row->successful_blocks,
            totalEarnedUsdc:         (string) $row->total_earned_usdc,
            totalStakedUsdc:         (string) $row->total_staked_usdc,
            successfulChallengesWon: (int) $row->successful_challenges_won,
            challengesLost:          (int) $row->challenges_lost,
            firstSeenAt:             $row->first_seen_at,
            lastActiveAt:            $row->last_active_at,
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
