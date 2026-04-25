<?php

declare(strict_types=1);

namespace App\Models\Event\Services;

use App\Models\Event\Brokers\BlockEventBroker;
use App\Models\Event\Entities\BlockEvent;

readonly class BlockEventService
{
    public function __construct(
        private BlockEventBroker $broker = new BlockEventBroker(),
    ) {
    }

    /**
     * @return BlockEvent[]
     */
    public function findRecent(int $limit, ?int $beforeId = null): array
    {
        return BlockEvent::buildArray($this->broker->findRecent($limit, $beforeId));
    }

    /**
     * @return BlockEvent[]
     */
    public function findRecentByEntryId(int $entryId, int $limit = 10): array
    {
        return BlockEvent::buildArray($this->broker->findRecentByEntryId($entryId, $limit));
    }

    public function countSince(string $sinceIso): int
    {
        return $this->broker->countSince($sinceIso);
    }

    public function sumValueProtectedAllTime(): string
    {
        return $this->broker->sumValueProtectedAllTime();
    }

    public function sumValueProtectedSince(string $sinceIso): string
    {
        return $this->broker->sumValueProtectedSince($sinceIso);
    }
}
