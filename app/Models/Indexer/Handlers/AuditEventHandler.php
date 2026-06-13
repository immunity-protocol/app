<?php

declare(strict_types=1);

namespace App\Models\Indexer\Handlers;

use App\Models\Event\Brokers\ContractEventBroker;

/**
 * Catch-all handler for Registry events that don't have a dedicated table:
 * Deposited, Withdrew, TreasuryWithdrawn, Seeded, OwnershipTransferred,
 * AddressBlocked, CallPatternBlocked, BytecodeBlocked, GraphTaintAdded,
 * SemanticPatternAdded.
 *
 * These all flow into event.contract_event with their decoded args as a
 * jsonb payload, idempotent on (tx_hash, log_index).
 */
class AuditEventHandler
{
    public function __construct(private readonly ContractEventBroker $broker)
    {
    }

    /**
     * @param array{event:string,args:array<string,mixed>,blockNumber:int,txHash:string,logIndex:int,address:string} $decoded
     */
    public function handle(array $decoded): bool
    {
        $txHashHex = strtolower(self::stripHex((string) $decoded['txHash']));
        // Qualify with the emitting contract ("ChallengeManager.VerdictRequested")
        // when known, matching the Reputation/BondLedger naming so the event feed
        // can render each type distinctly. Single-contract sources (no contract
        // key) keep the bare event name.
        $name = isset($decoded['contract']) && $decoded['contract'] !== null
            ? $decoded['contract'] . '.' . $decoded['event']
            : $decoded['event'];
        $id = $this->broker->insert(
            $name,
            $decoded['args'],
            (int) $decoded['blockNumber'],
            '\\x' . $txHashHex,
            (int) $decoded['logIndex'],
            gmdate('Y-m-d\TH:i:s\Z')
        );
        return $id !== null;
    }

    private static function stripHex(string $hex): string
    {
        if (str_starts_with($hex, '0x') || str_starts_with($hex, '0X')) {
            return substr($hex, 2);
        }
        return $hex;
    }
}
