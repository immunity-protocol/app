<?php

declare(strict_types=1);

namespace App\Models\Agent\Services;

use App\Models\Agent\Brokers\HeartbeatBroker;
use App\Models\Agent\Entities\Heartbeat;

readonly class HeartbeatService
{
    public function __construct(
        private HeartbeatBroker $broker = new HeartbeatBroker(),
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function upsert(array $data): void
    {
        $this->broker->upsert($data);
    }

    public function countOnline(int $withinSeconds = 300): int
    {
        return $this->broker->countOnline($withinSeconds);
    }

    /**
     * @return array<string, int>
     */
    public function countByRole(): array
    {
        return $this->broker->countByRole();
    }

    /**
     * @return Heartbeat[]
     */
    public function findAll(int $limit = 200): array
    {
        return Heartbeat::buildArray($this->broker->findAll($limit));
    }

    public function maxLastSeen(): ?string
    {
        return $this->broker->maxLastSeen();
    }
}
