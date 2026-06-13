<?php

declare(strict_types=1);

namespace App\Models\Core;

use RuntimeException;

/**
 * CIDv0 (dag-pb / sha2-256) ⇄ 32-byte digest mapping — the inverse of the
 * storage gateway's decode and the exact mirror of the SDK's storage/cid.ts.
 *
 * On-chain `evidenceCid` / `contextHash` store ONLY the 32-byte sha2-256
 * multihash digest (a bytes32). The fetch CID is reconstructed deterministically
 * as `base58btc(0x12 ‖ 0x20 ‖ digest)` → `Qm…` (46 chars). The CID shape is
 * pinned to Kubo's default CIDv0/dag-pb; CIDv1 (`b…`) is rejected. A mismatch
 * here means evidence will not resolve.
 */
final class Cid
{
    /** Multihash function code for sha2-256. */
    private const MH_SHA2_256 = 0x12;
    /** sha2-256 digest length in bytes. */
    private const DIGEST_LEN = 0x20;
    /** Total CIDv0 multihash length: 0x12 ‖ 0x20 ‖ 32-byte digest. */
    private const CIDV0_LEN = 2 + self::DIGEST_LEN;

    /** Bitcoin base58 alphabet (multibase base58btc). */
    private const B58_ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    /**
     * Reconstruct the canonical CIDv0 string from a raw 32-byte digest.
     * Always starts `Qm…`.
     */
    public static function digestToCidV0(string $digest): string
    {
        if (strlen($digest) !== self::DIGEST_LEN) {
            throw new RuntimeException('Cid: expected a raw 32-byte digest, got ' . strlen($digest) . ' bytes');
        }
        return self::base58btcEncode(chr(self::MH_SHA2_256) . chr(self::DIGEST_LEN) . $digest);
    }

    /**
     * Extract the raw 32-byte digest from a CIDv0/dag-pb/sha2-256 string.
     * Rejects CIDv1 (`b…`), non-sha2-256 multihashes, and malformed input.
     */
    public static function cidV0ToDigest(string $cid): string
    {
        if ($cid === '') {
            throw new RuntimeException('Cid: empty CID');
        }
        if (!str_starts_with($cid, 'Qm')) {
            throw new RuntimeException("Cid: unsupported CID '" . substr($cid, 0, 8) . "…': expected CIDv0 base58btc (prefix 'Qm')");
        }
        $bytes = self::base58btcDecode($cid);
        if (strlen($bytes) !== self::CIDV0_LEN) {
            throw new RuntimeException('Cid: malformed CIDv0: expected ' . self::CIDV0_LEN . ' bytes, got ' . strlen($bytes));
        }
        if (ord($bytes[0]) !== self::MH_SHA2_256 || ord($bytes[1]) !== self::DIGEST_LEN) {
            throw new RuntimeException('Cid: unsupported multihash; expected sha2-256 (0x12) length 32 (0x20)');
        }
        return substr($bytes, 2);
    }

    /** Convenience: 0x-prefixed 32-byte hex digest → `Qm…` (mirrors SDK hex32ToCid). */
    public static function hex32ToCid(string $hex32): string
    {
        $clean = self::stripHex($hex32);
        if (strlen($clean) !== 64 || !ctype_xdigit($clean)) {
            throw new RuntimeException("Cid: expected a 32-byte 0x hex digest, got: $hex32");
        }
        return self::digestToCidV0((string) hex2bin($clean));
    }

    /** Convenience: `Qm…` → 0x-prefixed 32-byte hex digest (mirrors SDK cidToHex32). */
    public static function cidToHex32(string $cid): string
    {
        return '0x' . bin2hex(self::cidV0ToDigest($cid));
    }

    /** base58btc encode (big-endian; leading zero bytes → leading '1's). */
    private static function base58btcEncode(string $bytes): string
    {
        $len = strlen($bytes);
        $zeros = 0;
        while ($zeros < $len && $bytes[$zeros] === "\0") {
            $zeros++;
        }

        $digits = [];
        for ($i = $zeros; $i < $len; $i++) {
            $carry = ord($bytes[$i]);
            foreach ($digits as $j => $d) {
                $carry += $d << 8;
                $digits[$j] = $carry % 58;
                $carry = intdiv($carry, 58);
            }
            while ($carry > 0) {
                $digits[] = $carry % 58;
                $carry = intdiv($carry, 58);
            }
        }

        $out = str_repeat('1', $zeros);
        for ($i = count($digits) - 1; $i >= 0; $i--) {
            $out .= self::B58_ALPHABET[$digits[$i]];
        }
        return $out;
    }

    /** base58btc decode (big-endian; leading '1's → leading zero bytes). */
    private static function base58btcDecode(string $str): string
    {
        $len = strlen($str);
        $zeros = 0;
        while ($zeros < $len && $str[$zeros] === '1') {
            $zeros++;
        }

        $bytes = [];
        for ($i = $zeros; $i < $len; $i++) {
            $val = strpos(self::B58_ALPHABET, $str[$i]);
            if ($val === false) {
                throw new RuntimeException("Cid: invalid base58btc character '{$str[$i]}'");
            }
            $carry = $val;
            foreach ($bytes as $j => $b) {
                $carry += $b * 58;
                $bytes[$j] = $carry & 0xff;
                $carry >>= 8;
            }
            while ($carry > 0) {
                $bytes[] = $carry & 0xff;
                $carry >>= 8;
            }
        }

        $count = count($bytes);
        $out = array_fill(0, $zeros + $count, 0);
        for ($i = 0; $i < $count; $i++) {
            $out[$zeros + $count - 1 - $i] = $bytes[$i];
        }
        return $out === [] ? '' : pack('C*', ...$out);
    }

    private static function stripHex(string $hex): string
    {
        return str_starts_with($hex, '0x') || str_starts_with($hex, '0X') ? substr($hex, 2) : $hex;
    }
}
