<?php

declare(strict_types=1);

namespace App\Models\Event\Entities;

use Zephyrus\Data\Entity;

class Activity extends Entity
{
    public int $id;
    public string $event_type;
    public ?int $entry_id = null;
    public $payload;
    public string $actor;
    public string $occurred_at;
}
