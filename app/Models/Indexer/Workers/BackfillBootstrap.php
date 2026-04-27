<?php

declare(strict_types=1);

namespace App\Models\Indexer\Workers;

use App\Models\Indexer\Brokers\StateBroker;
use App\Models\Indexer\Entities\State;

/**
 * Run once per process start to make sure indexer.state has a row with a
 * sensible last_processed_block. On a cold database the state row is missing;
 * we seed it to (deployBlock - 1) and mark the indexer as backfilling so the
 * supervisor knows to expect catch-up traffic.
 */
class BackfillBootstrap
{
    public function __construct(
        private readonly StateBroker $stateBroker,
        private readonly int $deployBlock,
    ) {
    }

    /**
     * @return array{seeded:bool,last_processed_block:int,mode:string}
     */
    public function bootstrap(): array
    {
        $existing = $this->stateBroker->find();
        if ($existing !== null) {
            return [
                'seeded' => false,
                'last_processed_block' => (int) $existing->last_processed_block,
                'mode' => (string) $existing->mode,
            ];
        }
        $row = $this->stateBroker->ensure($this->deployBlock - 1, State::MODE_BACKFILLING);
        return [
            'seeded' => true,
            'last_processed_block' => (int) $row->last_processed_block,
            'mode' => (string) $row->mode,
        ];
    }
}
