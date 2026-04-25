<?php

declare(strict_types=1);

namespace App\Models\Network\Entities;

use Zephyrus\Data\Entity;

class Stat extends Entity
{
    public int $id;
    public string $metric;
    public string $value;
    public string $captured_at;
    public $metadata;
}
