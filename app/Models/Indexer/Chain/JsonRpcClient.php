<?php

declare(strict_types=1);

namespace App\Models\Indexer\Chain;

use RuntimeException;

/**
 * Minimal JSON-RPC client for read-only EVM calls (eth_blockNumber, eth_getLogs).
 *
 * No state-changing methods. No private keys. No retries beyond a single attempt;
 * the worker loop's outer cadence is the retry policy.
 */
class JsonRpcClient
{
    private int $nextId = 1;

    public function __construct(
        private readonly string $rpcUrl,
        private readonly int $timeoutSeconds = 15,
    ) {
    }

    public function blockNumber(): int
    {
        $hex = $this->call('eth_blockNumber', []);
        return self::hexToInt((string) $hex);
    }

    /**
     * @param array<string, mixed> $filter eth_getLogs filter object
     * @return array<int, array<string, mixed>>
     */
    public function getLogs(array $filter): array
    {
        $logs = $this->call('eth_getLogs', [$filter]);
        if (!is_array($logs)) {
            return [];
        }
        return $logs;
    }

    /**
     * @param array<int, mixed> $params
     */
    public function call(string $method, array $params): mixed
    {
        $body = json_encode([
            'jsonrpc' => '2.0',
            'id'      => $this->nextId++,
            'method'  => $method,
            'params'  => $params,
        ], JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new RuntimeException("Failed to encode JSON-RPC request for $method");
        }

        $ch = curl_init($this->rpcUrl);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        $errmsg   = curl_error($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $response === false) {
            throw new RuntimeException("RPC transport error ($method): $errmsg");
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("RPC HTTP $status from $method: " . substr((string) $response, 0, 500));
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("RPC non-JSON response from $method");
        }
        if (isset($decoded['error'])) {
            $msg = is_array($decoded['error']) && isset($decoded['error']['message'])
                ? $decoded['error']['message']
                : json_encode($decoded['error']);
            throw new RuntimeException("RPC error from $method: $msg");
        }
        return $decoded['result'] ?? null;
    }

    public static function hexToInt(string $hex): int
    {
        if ($hex === '' || $hex === '0x') {
            return 0;
        }
        if (str_starts_with($hex, '0x') || str_starts_with($hex, '0X')) {
            $hex = substr($hex, 2);
        }
        return (int) hexdec($hex);
    }

    public static function intToHex(int $n): string
    {
        return '0x' . dechex($n);
    }
}
