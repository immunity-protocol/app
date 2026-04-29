<?php

declare(strict_types=1);

namespace App\Models\Antibody\Services;

use App\Models\Antibody\Brokers\PublisherBroker;
use App\Models\Antibody\Entities\Publisher;

readonly class PublisherService
{
    public function __construct(
        private PublisherBroker $broker = new PublisherBroker(),
    ) {
    }

    public function findByAddressHex(string $addressHex): ?Publisher
    {
        return Publisher::build($this->broker->findByAddressHex($addressHex));
    }

    /**
     * @return Publisher[]
     */
    public function findTopByAntibodies(int $limit): array
    {
        return Publisher::buildArray($this->broker->findTopByAntibodies($limit));
    }

    public function countAll(): int
    {
        return $this->broker->countAll();
    }

    /**
     * Raw stdClass rows for the leaderboard page. Returned as-is (not
     * wrapped in the Publisher entity) because the leaderboard needs the
     * derived `total_value_protected_usd` column that the entity doesn't
     * model.
     *
     * @return \stdClass[]
     */
    public function listWithStatsPage(int $offset, int $limit): array
    {
        return $this->broker->listWithStatsPage($offset, $limit);
    }
}
