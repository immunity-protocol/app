<?php

declare(strict_types=1);

namespace App\Models\Gateway;

use stdClass;

/**
 * Verifies a GatewayRequestV1 (immunity-sdk/src/storage/client.ts). Fail-closed:
 * any structural, hash, signature, freshness, or size problem raises a
 * GatewayException with a 4xx. On success returns a VerifiedRequest.
 *
 * Checks (Gateway.md §3):
 *   - schema + field shapes (publisher / payloadHash / signature / nonce / payload)
 *   - size cap on the raw body (single-block, cheap to pin)
 *   - payloadHash == keccak256(canonicalJson(payload))   [byte-identical to SDK]
 *   - EIP-191 recovered signer == publisher
 *   - timestamp within ±5 min (replay window; ms)
 */
final class GatewayRequestVerifier
{
    public const string SCHEMA = 'immunity/gateway-request/v1';
    public const string ENVELOPE_SCHEMA = 'immunity/antibody-envelope/v1';
    /** The per-check (Tier-3 CRE novel-verification) request envelope. */
    public const string PER_CHECK_ENVELOPE_SCHEMA = 'immunity/per-check-request/v1';
    /** Envelope schemas the gateway will pin (antibody publish + per-check request). */
    public const array ACCEPTED_ENVELOPE_SCHEMAS = [
        self::ENVELOPE_SCHEMA,
        self::PER_CHECK_ENVELOPE_SCHEMA,
    ];

    /** Default ±5 min freshness window (ms). */
    public const int DEFAULT_WINDOW_MS = 5 * 60 * 1000;
    /** Default raw-body cap (~256 KB) — keeps the upload a single block. */
    public const int DEFAULT_MAX_BODY_BYTES = 262144;

    public function __construct(
        private readonly int $windowMs = self::DEFAULT_WINDOW_MS,
        private readonly int $maxBodyBytes = self::DEFAULT_MAX_BODY_BYTES,
    ) {
    }

    /**
     * @param stdClass $request   The decoded request body (json_decode, NOT assoc).
     * @param int      $rawBytes  Byte length of the raw request body.
     * @param int      $nowMs     Current time in Unix ms (injectable for tests).
     */
    public function verify(stdClass $request, int $rawBytes, int $nowMs): VerifiedRequest
    {
        if ($rawBytes > $this->maxBodyBytes) {
            throw new GatewayException(413, 'payload too large');
        }

        if (($request->schema ?? null) !== self::SCHEMA) {
            throw new GatewayException(400, 'unsupported or missing schema');
        }

        $publisher = $this->requireAddress($request->publisher ?? null, 'publisher');
        $payloadHash = $this->requireHex32($request->payloadHash ?? null, 'payloadHash');
        $signature = $this->requireSignature($request->signature ?? null);
        $nonce = $this->requireNonce($request->nonce ?? null);
        $timestamp = $this->requireTimestamp($request->timestamp ?? null);

        $payload = $request->payload ?? null;
        if (!$payload instanceof stdClass) {
            throw new GatewayException(400, 'payload must be an object');
        }
        $envelope = $payload->envelope ?? null;
        if (!$envelope instanceof stdClass) {
            throw new GatewayException(400, 'payload.envelope must be an object');
        }
        if (!in_array($envelope->schema ?? null, self::ACCEPTED_ENVELOPE_SCHEMAS, true)) {
            throw new GatewayException(400, 'unsupported or missing envelope schema');
        }

        $encryptedContext = null;
        if (property_exists($payload, 'encryptedContext') && $payload->encryptedContext !== null) {
            if (!is_string($payload->encryptedContext) || !preg_match('/^0x[0-9a-fA-F]*$/', $payload->encryptedContext)) {
                throw new GatewayException(400, 'encryptedContext must be 0x hex');
            }
            $encryptedContext = $payload->encryptedContext;
        }

        // (a) payloadHash must equal keccak256(canonicalJson(payload)).
        $recomputed = CanonicalJson::payloadHash($payload);
        if (!hash_equals(strtolower($recomputed), strtolower($payloadHash))) {
            throw new GatewayException(400, 'payloadHash does not match payload');
        }

        // (b) signature must recover to the claimed publisher.
        if (!Eip191Verifier::verify($payloadHash, $signature, $publisher)) {
            throw new GatewayException(401, 'signature does not match publisher');
        }

        // (c) freshness window.
        if (abs($nowMs - $timestamp) > $this->windowMs) {
            throw new GatewayException(400, 'timestamp outside freshness window');
        }

        return new VerifiedRequest(
            publisher: strtolower($publisher),
            payloadHash: strtolower($payloadHash),
            nonce: strtolower($nonce),
            timestamp: $timestamp,
            envelope: $envelope,
            encryptedContext: $encryptedContext,
        );
    }

    private function requireAddress(mixed $value, string $field): string
    {
        if (!is_string($value) || !preg_match('/^0x[0-9a-fA-F]{40}$/', $value)) {
            throw new GatewayException(400, "$field must be a 0x 20-byte address");
        }
        return $value;
    }

    private function requireHex32(mixed $value, string $field): string
    {
        if (!is_string($value) || !preg_match('/^0x[0-9a-fA-F]{64}$/', $value)) {
            throw new GatewayException(400, "$field must be 0x 32-byte hex");
        }
        return $value;
    }

    private function requireSignature(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^0x[0-9a-fA-F]{130}$/', $value)) {
            throw new GatewayException(400, 'signature must be 0x 65-byte hex');
        }
        return $value;
    }

    private function requireNonce(mixed $value): string
    {
        // SDK nonce is 16 random bytes hex (32 chars); accept any short hex token.
        if (!is_string($value) || !preg_match('/^[0-9a-fA-F]{8,128}$/', $value)) {
            throw new GatewayException(400, 'nonce must be a hex token');
        }
        return $value;
    }

    private function requireTimestamp(mixed $value): int
    {
        // JSON numbers decode to int/float; reject non-numeric.
        if (!is_int($value) && !(is_float($value) && floor($value) === $value)) {
            throw new GatewayException(400, 'timestamp must be an integer (ms)');
        }
        return (int) $value;
    }
}
