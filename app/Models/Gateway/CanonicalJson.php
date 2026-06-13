<?php

declare(strict_types=1);

namespace App\Models\Gateway;

use kornrunner\Keccak;
use stdClass;

/**
 * Byte-identical reimplementation of the SDK's `canonicalJson` (storage/client.ts)
 * so the gateway recomputes the exact same `payloadHash` the publisher signed.
 *
 * SDK semantics: recursively sort object keys, then `JSON.stringify` — no spaces,
 * `/` NOT escaped, raw UTF-8 (not `\u`). Arrays keep their order.
 *
 * PHP match: `json_encode($x, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)`.
 * We decode the request body as stdClass (NOT assoc arrays) so the JSON
 * object/array distinction is preserved — decoding `{}` to an assoc array would
 * round-trip to `[]` and diverge from JS. Empty objects re-encode as `{}`,
 * empty arrays as `[]`.
 *
 * Numbers: the envelope schema uses only integers (flavor, chainId, size), which
 * PHP and JS format identically. (Floats are the one place json_encode can
 * diverge from JSON.stringify, e.g. 1.0 vs 1 — none occur in this contract.)
 */
final class CanonicalJson
{
    /**
     * Serialize a decoded JSON value (stdClass | array | scalar | null) with
     * recursively sorted object keys, byte-identical to the SDK.
     */
    public static function encode(mixed $value): string
    {
        $encoded = json_encode(self::sortKeys($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new \RuntimeException('canonicalJson: json_encode failed: ' . json_last_error_msg());
        }
        return $encoded;
    }

    /**
     * keccak256(utf8(canonicalJson(value))) as a lowercase 0x-prefixed 32-byte
     * hex string — the `payloadHash` the publisher signs.
     */
    public static function payloadHash(mixed $value): string
    {
        return '0x' . Keccak::hash(self::encode($value), 256);
    }

    private static function sortKeys(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $keys = array_keys(get_object_vars($value));
            // JS uses Object.keys().sort() — lexicographic by UTF-16 code unit.
            // For the ASCII identifier keys in this contract that equals byte order.
            sort($keys, SORT_STRING);
            $out = new stdClass();
            foreach ($keys as $key) {
                $out->{$key} = self::sortKeys($value->{$key});
            }
            return $out;
        }
        if (is_array($value)) {
            // JSON arrays only (lists) — preserve order.
            return array_map([self::class, 'sortKeys'], $value);
        }
        return $value;
    }
}
