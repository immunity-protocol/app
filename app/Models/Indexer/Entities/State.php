<?php

declare(strict_types=1);

namespace App\Models\Indexer\Entities;

use Zephyrus\Data\Entity;

class State extends Entity
{
    public const MODE_BACKFILLING = 'backfilling';
    public const MODE_LIVE = 'live';

    public int $chain_id;
    public int $last_processed_block = 0;
    public string $mode = self::MODE_LIVE;
    public string $last_run_at;

    public function isBackfilling(): bool
    {
        return $this->mode === self::MODE_BACKFILLING;
    }
}
