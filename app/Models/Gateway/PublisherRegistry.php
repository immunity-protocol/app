<?php

declare(strict_types=1);

namespace App\Models\Gateway;

use App\Models\Indexer\Chain\JsonRpcClient;
use kornrunner\Keccak;
use RuntimeException;

/**
 * The sybil/spam gate: only publishers that are registered (bonded) on the
 * deployed PublisherRegistrar may spend protocol storage.
 *
 * Reads `PublisherRegistrar.isRegistered(address) → bool` on Base Sepolia via a
 * read-only eth_call. No keys, no writes.
 *
 *   registrar  0x762CF28bE7502CC99B6286076e9b4Fb71EE84002  (Base Sepolia, 84532)
 *   ABI        isRegistered(address account) view returns (bool)   [exact]
 */
final class PublisherRegistry implements RegistrationGate
{
    /** Live PublisherRegistrar on Base Sepolia. */
    public const string DEFAULT_REGISTRAR = '0x762CF28bE7502CC99B6286076e9b4Fb71EE84002';
    /** Public Base Sepolia RPC fallback when BASE_SEPOLIA_RPC_URL is unset. */
    public const string DEFAULT_RPC_URL = 'https://sepolia.base.org';

    private readonly JsonRpcClient $rpc;
    private readonly string $registrar;

    public function __construct(?JsonRpcClient $rpc = null, ?string $registrar = null)
    {
        $this->rpc = $rpc ?? new JsonRpcClient(
            (string) ($_ENV['BASE_SEPOLIA_RPC_URL'] ?? getenv('BASE_SEPOLIA_RPC_URL') ?: self::DEFAULT_RPC_URL),
        );
        $this->registrar = $registrar
            ?? (string) ($_ENV['PUBLISHER_REGISTRAR_ADDRESS'] ?? getenv('PUBLISHER_REGISTRAR_ADDRESS') ?: self::DEFAULT_REGISTRAR);
    }

    /**
     * True iff `isRegistered(address)` returns a non-zero word on-chain.
     * Transport/RPC errors propagate (caller treats them as fail-closed 5xx),
     * never as a false "registered".
     */
    public function isRegistered(string $address): bool
    {
        $clean = strtolower(preg_replace('/^0x/i', '', $address) ?? '');
        if (strlen($clean) !== 40 || !ctype_xdigit($clean)) {
            throw new RuntimeException('isRegistered: invalid address');
        }
        $data = '0x' . self::selector() . str_pad($clean, 64, '0', STR_PAD_LEFT);

        $result = $this->rpc->call('eth_call', [
            ['to' => $this->registrar, 'data' => $data],
            'latest',
        ]);

        if (!is_string($result)) {
            throw new RuntimeException('isRegistered: unexpected eth_call result');
        }
        // bool word: 0x000…000 (false) or 0x000…001 (true).
        $word = ltrim(preg_replace('/^0x/i', '', $result) ?? '', '0');
        return $word !== '';
    }

    /** First 4 bytes of keccak256("isRegistered(address)") as 8 hex chars. */
    private static function selector(): string
    {
        return substr(Keccak::hash('isRegistered(address)', 256), 0, 8);
    }
}
