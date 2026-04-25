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
}
