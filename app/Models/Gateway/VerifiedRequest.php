<?php

declare(strict_types=1);

namespace App\Models\Gateway;

use stdClass;

/**
 * A GatewayRequestV1 that has passed structural, payloadHash, and signature
 * verification. Carries the normalized fields the rest of the pipeline needs.
 */
final class VerifiedRequest
{
    public function __construct(
        /** Lowercased 0x publisher address (== recovered signer). */
        public readonly string $publisher,
        /** 0x-prefixed 32-byte payload hash (matches the recomputed hash). */
        public readonly string $payloadHash,
        /** Lowercased nonce hex (replay key). */
        public readonly string $nonce,
        /** Unix milliseconds from the request. */
        public readonly int $timestamp,
        /** The public envelope object (always present). */
        public readonly stdClass $envelope,
        /** Packed ECIES hex blob, or null when no context was encrypted. */
        public readonly ?string $encryptedContext,
    ) {
    }
}
