<?php

declare(strict_types=1);

namespace App\Models\Event\Services;

use App\Models\Event\Brokers\ActivityBroker;
use App\Models\Event\Entities\Activity;

readonly class ActivityService
{
    public function __construct(
        private ActivityBroker $broker = new ActivityBroker(),
    ) {
    }

    /**
     * @return Activity[]
     */
    public function findRecent(int $limit, ?int $beforeId = null): array
    {
        return Activity::buildArray($this->broker->findRecent($limit, $beforeId));
    }

    public function countByType(string $eventType): int
    {
        return $this->broker->countByType($eventType);
    }
}
