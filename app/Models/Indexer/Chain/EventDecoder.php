<?php

declare(strict_types=1);

namespace App\Models\Indexer\Chain;

use RuntimeException;

/**
 * Decodes contract event logs (eth_getLogs result items) into typed payloads.
 *
 * Handles fixed-size scalar types (uint*, int*, address, bool, bytes32, bytes4,
 * uint8 enums) in topics/data slots, plus non-indexed dynamic `string` and
 * `bytes` in the data section (head offset + tail length/payload) — needed for
 * PublisherRegistrar.Registered(label) and L2Registry.TextChanged/SubnodeCreated.
 * Arrays and indexed dynamic types are not used by the indexed event set.
 */
class EventDecoder
{
    public function __construct(private readonly EventAbi $abi)
    {
    }

    /**
     * @param array<string, mixed> $log a single eth_getLogs result entry
     * @return array{
     *   contract: string|null,
     *   event: string,
     *   args: array<string, mixed>,
     *   blockNumber: int,
     *   txHash: string,
     *   logIndex: int,
     *   address: string
     * }|null  null if the address/topic0 doesn't match any known event
     */
    public function decode(array $log): ?array
    {
        $topics = $log['topics'] ?? [];
        if (!is_array($topics) || count($topics) === 0) {
            return null;
        }
        $topic0 = strtolower((string) $topics[0]);
        $address = strtolower((string) ($log['address'] ?? ''));

        // Address-aware resolution: with a multi-contract registry we first map
        // the emitting address to its ABI, so same-named events on different
        // contracts (Registry.Matured vs Reputation.Matured) and identical
        // signatures (TreasuryWithdrawn) never collide. Single-contract sources
        // (e.g. MirrorAbi) keep the flat topic0 path.
        $abiSource = $this->abi;
        $contractName = null;
        if ($this->abi instanceof ContractRegistry) {
            $contract = $this->abi->contractAt($address);
            if ($contract === null) {
                return null;
            }
            $abiSource = $contract;
            $contractName = $contract->name();
        }

        $abiItem = $abiSource->eventByTopic($topic0);
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
                $args[$name] = self::decodeWord($type, $word);
                continue;
            }
            // Non-indexed: each param occupies one head word — the value inline
            // for static types, or a byte-offset into the data tail for dynamic
            // `string`/`bytes`.
            $headWord = $words[$wordIdx++] ?? str_repeat('0', 64);
            if (self::isDynamic($type)) {
                $args[$name] = self::decodeDynamic($type, $headWord, $words);
            } else {
                $args[$name] = self::decodeWord($type, $headWord);
            }
        }

        return [
            'contract'    => $contractName,
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

    /** True for ABI dynamic types we support in the data tail. */
    private static function isDynamic(string $type): bool
    {
        return $type === 'string' || $type === 'bytes';
    }

    /**
     * Decode a non-indexed dynamic `string`/`bytes`. The head word is a byte
     * offset (from the start of the data tuple) to a tail slot holding the
     * length followed by the payload words.
     *
     * @param string[] $words 64-char hex words of the whole data section
     */
    private static function decodeDynamic(string $type, string $headWord, array $words): string
    {
        $offsetBytes = self::hexToInt(ltrim($headWord, '0'));
        $offsetWord = intdiv($offsetBytes, 32);
        $len = self::hexToInt(ltrim($words[$offsetWord] ?? '', '0'));
        if ($len === 0) {
            return $type === 'string' ? '' : '0x';
        }
        $dataHex = '';
        $numWords = intdiv($len + 31, 32);
        for ($i = 1; $i <= $numWords; $i++) {
            $dataHex .= $words[$offsetWord + $i] ?? '';
        }
        $payload = (string) hex2bin(substr($dataHex, 0, $len * 2));
        return $type === 'string' ? $payload : '0x' . bin2hex($payload);
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
