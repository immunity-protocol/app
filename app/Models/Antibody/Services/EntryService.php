<?php

declare(strict_types=1);

namespace App\Models\Antibody\Services;

use App\Models\Antibody\Brokers\EntryBroker;
use App\Models\Antibody\Entities\Entry;

readonly class EntryService
{
    public function __construct(
        private EntryBroker $broker = new EntryBroker(),
    ) {
    }

    public function findById(int $id): ?Entry
    {
        return Entry::build($this->broker->findById($id));
    }

    public function findByImmId(string $immId): ?Entry
    {
        return Entry::build($this->broker->findByImmId($immId));
    }

    /**
     * @return Entry[]
     */
    public function findRecent(int $limit, ?int $beforeId = null): array
    {
        return Entry::buildArray($this->broker->findRecent($limit, $beforeId));
    }

    public function countActive(): int
    {
        return $this->broker->countActive();
    }

    /**
     * @return array<string, int>
     */
    public function countByType(): array
    {
        return $this->broker->countByType();
    }
}
