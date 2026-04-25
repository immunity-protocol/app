<?php

declare(strict_types=1);

namespace App\Models\Event\Services;

use App\Models\Event\Brokers\CheckEventBroker;

readonly class CheckEventService
{
    public function __construct(
        private CheckEventBroker $broker = new CheckEventBroker(),
    ) {
    }

    public function countSince(string $sinceIso): int
    {
        return $this->broker->countSince($sinceIso);
    }

    public function countCacheHitsSince(string $sinceIso): int
    {
        return $this->broker->countCacheHitsSince($sinceIso);
    }

    public function countTeeRoundTripsSince(string $sinceIso): int
    {
        return $this->broker->countTeeRoundTripsSince($sinceIso);
    }

    /**
     * @return array<string, int>
     */
    public function countByDecisionSince(string $sinceIso): array
    {
        return $this->broker->countByDecisionSince($sinceIso);
    }
}
