<?php

declare(strict_types=1);

namespace App\Models\Antibody\Services;

use App\Models\Antibody\Brokers\MirrorBroker;
use App\Models\Antibody\Entities\Mirror;

readonly class MirrorService
{
    public function __construct(
        private MirrorBroker $broker = new MirrorBroker(),
    ) {
    }

    /**
     * @return Mirror[]
     */
    public function findByEntryId(int $entryId): array
    {
        return Mirror::buildArray($this->broker->findByEntryId($entryId));
    }

    public function countActive(): int
    {
        return $this->broker->countActive();
    }

    /**
     * @return array<string, int>
     */
    public function countActiveByChain(): array
    {
        return $this->broker->countActiveByChain();
    }
}
