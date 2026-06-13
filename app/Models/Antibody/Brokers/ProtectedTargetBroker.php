<?php

declare(strict_types=1);

namespace App\Models\Antibody\Brokers;

use App\Models\Core\Broker;

class ProtectedTargetBroker extends Broker
{
    /**
     * True iff the given lowercase, 0x-stripped address hex is a currently
     * protected target. Protected members can never be hard-blocked.
     */
    public function isProtected(string $addressHex): bool
    {
        $row = $this->selectOne(
            "SELECT 1 AS hit FROM antibody.protected_target
              WHERE address = decode(?, 'hex') AND protected = true",
            [$addressHex]
        );
        return $row !== null;
    }
}
