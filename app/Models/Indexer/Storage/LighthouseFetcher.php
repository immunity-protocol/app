<?php

declare(strict_types=1);

namespace App\Models\Indexer\Storage;

use App\Models\Core\Cid;
use Lighthouse\LighthouseService;
use RuntimeException;

/**
 * Fetches an antibody public envelope from Lighthouse/IPFS by reconstructing
 * the CID from the on-chain 32-byte digest. Replaces the old 0G NodeBridge
 * (proc_open → og-download.mjs).
 *
 * The on-chain `evidence_cid` (bytes32) is the CIDv0/dag-pb sha2-256 multihash
 * digest. We rebuild `base58btc(0x12 ‖ 0x20 ‖ digest)` → `Qm…` (see Cid) — this
 * MUST match the SDK and gateway exactly — then GET the keyless public gateway.
 *
 * Returns the parsed envelope array, null for a zero digest (no evidence), or
 * throws a RuntimeException on transport/parse failure. The caller
 * (HydrationWorker) maps exceptions to queue backoff vs. failed.
 */
class LighthouseFetcher
{
    public function __construct(
        private readonly int $timeoutSeconds = 30,
    ) {
    }

    /**
     * @param string $digest the raw 32-byte on-chain evidence_cid
     * @return array<string, mixed>|null  null when the digest is all-zero
     */
    public function downloadEnvelope(string $digest): ?array
    {
        if (strlen($digest) !== 32) {
            throw new RuntimeException('LighthouseFetcher: expected a raw 32-byte digest, got ' . strlen($digest) . ' bytes');
        }
        if ($digest === str_repeat("\0", 32)) {
            return null;
        }

        $cid = Cid::digestToCidV0($digest);
        $url = LighthouseService::getFileUrl($cid);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_FAILONERROR    => false,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno !== 0 || $body === false) {
            throw new RuntimeException("LighthouseFetcher: transport error for $cid: $error");
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException("LighthouseFetcher: HTTP $httpCode fetching $cid");
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("LighthouseFetcher: malformed envelope JSON for $cid");
        }
        return $decoded;
    }
}
