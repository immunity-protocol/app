<?php

declare(strict_types=1);

namespace App\Models\Gateway;

/**
 * Whether an address is a registered (bonded) publisher allowed to spend
 * protocol storage. Implemented by PublisherRegistry (Base Sepolia eth_call);
 * stubbed in tests.
 */
interface RegistrationGate
{
    public function isRegistered(string $address): bool;
}
