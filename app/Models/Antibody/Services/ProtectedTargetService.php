<?php

declare(strict_types=1);

namespace App\Models\Antibody\Services;

use App\Models\Antibody\Brokers\ProtectedTargetBroker;

readonly class ProtectedTargetService
{
    public function __construct(
        private ProtectedTargetBroker $broker = new ProtectedTargetBroker(),
    ) {
    }

    /**
     * True iff the given address (0x-prefixed or bare hex) is on the protected
     * set. Normalizes case and the 0x prefix; non-address input returns false.
     */
    public function isProtected(string $address): bool
    {
        $clean = strtolower(preg_replace('/^0x/i', '', trim($address)) ?? '');
        if (strlen($clean) !== 40 || !ctype_xdigit($clean)) {
            return false;
        }
        return $this->broker->isProtected($clean);
    }
}
