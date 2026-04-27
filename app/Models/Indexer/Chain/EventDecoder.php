<?php

declare(strict_types=1);

namespace App\Models\Indexer\Chain;

use RuntimeException;

/**
 * Decodes Registry event logs (eth_getLogs result items) into typed payloads.
 *
 * The Registry only emits fixed-size scalar types (uint*, int*, address, bool,
 * bytes32, bytes4, uint8 for enums). No dynamic types (string, bytes, arrays).
 * That keeps the decoder small: every parameter occupies exactly 32 bytes in
 * the data slot or is a topic.
 */
class EventDecoder
{
    public function __construct(private readonly EventAbi $abi)
    {
    }

    /**
     * @param array<string, mixed> $log a single eth_getLogs result entry
     * @return array{
     *   event: string,
     *   args: array<string, mixed>,
     *   blockNumber: int,
     *   txHash: string,
     *   logIndex: int,
     *   address: string
     * }|null  null if topic0 doesn't match any known event
     */
    public function decode(array $log): ?array
    {
        $topics = $log['topics'] ?? [];
        if (!is_array($topics) || count($topics) === 0) {
            return null;
        }
        $topic0 = strtolower((string) $topics[0]);
        $abiItem = $this->abi->eventByTopic($topic0);
        if ($abiItem === null) {
            return null;
        }

        $args = [];
        $indexedTopicIdx = 1;
        $data = self::stripHex((string) ($log['data'] ?? '0x'));
        $words = self::splitIntoWords($data);
        $wordIdx = 0;

        foreach ($abiItem['inputs'] as $input) {
            $name = (string) $input['name'];
            $type = (string) $input['type'];
            $isIndexed = !empty($input['indexed']);
            if ($isIndexed) {
                $word = self::stripHex((string) ($topics[$indexedTopicIdx++] ?? '0x'));
            } else {
                $word = $words[$wordIdx++] ?? str_repeat('0', 64);
            }
            $args[$name] = self::decodeWord($type, $word);
        }

        return [
            'event'       => (string) $abiItem['name'],
            'args'        => $args,
            'blockNumber' => JsonRpcClient::hexToInt((string) ($log['blockNumber'] ?? '0x0')),
            'txHash'      => strtolower((string) ($log['transactionHash'] ?? '0x')),
            'logIndex'    => JsonRpcClient::hexToInt((string) ($log['logIndex'] ?? '0x0')),
            'address'     => strtolower((string) ($log['address'] ?? '0x')),
        ];
    }

    /**
     * Decode one 32-byte (64 hex chars) word as the given Solidity type.
     *
     * Returns:
     *   - address: lowercase 0x-prefixed 40-hex string
     *   - uintN/intN: int when N <= 32, decimal string when larger (for safety on uint256)
     *   - bool: PHP bool
     *   - bytes32 / bytes4 / bytesN: lowercase 0x-prefixed hex
     *   - uint8 enums: int (caller maps to enum names)
     */
    public static function decodeWord(string $type, string $word): mixed
    {
        $word = ltrim($word, "\t\n\r\0\x0B ");
        if (strlen($word) < 64) {
            $word = str_pad($word, 64, '0', STR_PAD_LEFT);
        }
        $word = strtolower($word);

        if ($type === 'address') {
            return '0x' . substr($word, 24);
        }
        if ($type === 'bool') {
            return self::hexToInt(substr($word, -2)) !== 0;
        }
        if (str_starts_with($type, 'bytes')) {
            $sizeStr = substr($type, 5);
            if ($sizeStr === '') {
                throw new RuntimeException("EventDecoder: dynamic 'bytes' type unsupported in static-only event ABI");
            }
            $size = (int) $sizeStr;
            return '0x' . substr($word, 0, $size * 2);
        }
        if (str_starts_with($type, 'uint') || str_starts_with($type, 'int')) {
            $bits = (int) preg_replace('/[^0-9]/', '', $type);
            if ($bits === 0) {
                $bits = 256;
            }
            $hex = ltrim($word, '0');
            if ($hex === '') {
                $hex = '0';
            }
            if ($bits <= 32) {
                return (int) hexdec($hex);
            }
            return self::hexToDecimalString($word);
        }
        throw new RuntimeException("EventDecoder: unsupported type '$type'");
    }

    private static function stripHex(string $hex): string
    {
        if (str_starts_with($hex, '0x') || str_starts_with($hex, '0X')) {
            return substr($hex, 2);
        }
        return $hex;
    }

    /**
     * @return string[] hex words (64 chars each)
     */
    private static function splitIntoWords(string $hex): array
    {
        if ($hex === '') {
            return [];
        }
        return str_split($hex, 64);
    }

    private static function hexToInt(string $hex): int
    {
        if ($hex === '') {
            return 0;
        }
        return (int) hexdec($hex);
    }

    /**
     * Convert an unsigned 256-bit hex word to a decimal string using GMP if
     * available, otherwise BC math, otherwise a hand-rolled fallback.
     */
    public static function hexToDecimalString(string $hex): string
    {
        $hex = ltrim(strtolower($hex), '0');
        if ($hex === '') {
            return '0';
        }
        if (function_exists('gmp_strval')) {
            return gmp_strval(gmp_init($hex, 16), 10);
        }
        if (function_exists('bcadd')) {
            $dec = '0';
            $len = strlen($hex);
            for ($i = 0; $i < $len; $i++) {
                $dec = bcmul($dec, '16', 0);
                $dec = bcadd($dec, (string) hexdec($hex[$i]), 0);
            }
            return $dec;
        }
        // Fallback: PHP's hexdec returns float for >PHP_INT_MAX; precision lost.
        return (string) hexdec($hex);
    }
}
