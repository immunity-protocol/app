<?php

declare(strict_types=1);

namespace App\Models\Gateway;

use Elliptic\EC;
use InvalidArgumentException;
use kornrunner\Keccak;

/**
 * EIP-191 (personal_sign) signer recovery for the gateway.
 *
 * The SDK signs `signer.signMessage(getBytes(payloadHash))` — a personal_sign
 * over the 32 RAW bytes of `payloadHash` (NOT the hex string). So the prefixed
 * digest is:
 *
 *   keccak256( "\x19Ethereum Signed Message:\n32" || payloadHashBytes(32) )
 *
 * We recover the secp256k1 public key from (r, s, v) and derive the address.
 * Ported from CodeQuill's SiweVerifier::recoverAddressEIP191, adapted to a
 * raw-bytes message (length 32) instead of a UTF-8 string.
 */
final class Eip191Verifier
{
    /**
     * Recover the lowercased 0x address that signed `payloadHash`.
     *
     * @param string $payloadHash 0x-prefixed 32-byte hex.
     * @param string $signature   0x-prefixed 65-byte hex (r‖s‖v).
     */
    public static function recover(string $payloadHash, string $signature): string
    {
        $hashHex = self::stripHex($payloadHash);
        if (strlen($hashHex) !== 64 || !ctype_xdigit($hashHex)) {
            throw new InvalidArgumentException('payloadHash must be 32-byte hex');
        }
        $hashBytes = hex2bin($hashHex);
        if ($hashBytes === false) {
            throw new InvalidArgumentException('payloadHash is not valid hex');
        }

        $prefix = "\x19Ethereum Signed Message:\n" . strlen($hashBytes); // \n32
        $digestHex = Keccak::hash($prefix . $hashBytes, 256);

        $sig = self::stripHex($signature);
        if (strlen($sig) !== 130 || !ctype_xdigit($sig)) {
            throw new InvalidArgumentException('signature must be 65-byte hex');
        }
        $r = substr($sig, 0, 64);
        $s = substr($sig, 64, 64);
        $v = hexdec(substr($sig, 128, 2));
        if ($v >= 27) {
            $v -= 27; // normalize 27/28 -> 0/1
        }
        if ($v !== 0 && $v !== 1) {
            throw new InvalidArgumentException('signature recovery id out of range');
        }

        $ec = new EC('secp256k1');
        $pubPoint = $ec->recoverPubKey(
            gmp_init($digestHex, 16),
            ['r' => gmp_init($r, 16), 's' => gmp_init($s, 16)],
            $v,
        );

        return self::pubKeyToAddress($pubPoint->encode('hex', false));
    }

    /**
     * True iff `signature` over `payloadHash` recovers to `expectedAddress`
     * (case-insensitive). Any malformed input recovers to a mismatch rather
     * than throwing through to the caller — callers treat false as fail-closed.
     */
    public static function verify(string $payloadHash, string $signature, string $expectedAddress): bool
    {
        try {
            $recovered = self::recover($payloadHash, $signature);
        } catch (\Throwable) {
            return false;
        }
        return hash_equals(strtolower(self::ensureHexPrefix($expectedAddress)), strtolower($recovered));
    }

    private static function pubKeyToAddress(string $pubUncompressedHex): string
    {
        $hex = self::stripHex($pubUncompressedHex);
        $bin = hex2bin($hex);
        if ($bin === false || strlen($bin) < 65 || $bin[0] !== "\x04") {
            throw new InvalidArgumentException('invalid recovered public key');
        }
        $body = substr($bin, 1); // drop 0x04 prefix byte
        $hash = Keccak::hash($body, 256);
        return '0x' . substr($hash, 24); // last 20 bytes
    }

    private static function stripHex(string $value): string
    {
        return preg_replace('/^0x/i', '', $value) ?? $value;
    }

    private static function ensureHexPrefix(string $value): string
    {
        return str_starts_with(strtolower($value), '0x') ? $value : '0x' . $value;
    }
}
