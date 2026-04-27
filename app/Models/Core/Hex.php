<?php

declare(strict_types=1);

namespace App\Models\Core;

/**
 * Tiny helpers for the project's bytea/hex conventions.
 *
 * The schema stores 32-byte fields (keccak_id, evidence_cid, attestation,
 * publisher, etc.) as `bytea`. Brokers sanitize PDO_PGSQL stream resources
 * to PHP strings, so consumers receive raw bytes. Empty / all-zero bytes
 * are common (an SDK publication that didn't supply a particular field
 * leaves it as bytes32(0)) and templates need to detect this to render
 * an honest empty-state instead of a literal 0x000…000.
 */
final class Hex
{
    /**
     * True if the given raw bytea is empty or all zero bytes.
     */
    public static function isZero(?string $bytes): bool
    {
        if ($bytes === null || $bytes === '') {
            return true;
        }
        return ltrim($bytes, "\0") === '';
    }

    /**
     * Render raw bytea as a 0x-prefixed lowercase hex string.
     */
    public static function toHex(string $bytes): string
    {
        return '0x' . bin2hex($bytes);
    }
}
