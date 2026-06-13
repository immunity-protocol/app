<?php

declare(strict_types=1);

namespace App\Models\Gateway;

use App\Models\Gateway\Brokers\GatewayUploadBroker;
use App\Models\Gateway\Storage\EvidenceStore;
use JsonException;
use stdClass;

/**
 * The /evidence pipeline, isolated from HTTP so it can be driven from tests
 * with a raw body string. Fail-closed: every rejection raises a
 * GatewayException carrying the 4xx to return; upstream failures (RPC, upload)
 * propagate as other throwables (the controller maps those to 502).
 *
 * Order is deliberate — reject cheaply before spending an upload:
 *   verify signature/hash/freshness → isRegistered → replay → quota → store → record.
 */
final class GatewayService
{
    public function __construct(
        private readonly GatewayRequestVerifier $verifier,
        private readonly RegistrationGate $registry,
        private readonly EvidenceStore $store,
        private readonly GatewayUploadBroker $broker,
        private readonly int $quotaPerHour,
    ) {
    }

    /**
     * Process a raw request body. Returns the success response body
     * `{ evidenceCid, contextCid? }`.
     *
     * @return array{evidenceCid: string, contextCid?: string}
     */
    public function process(string $rawBody, int $nowMs): array
    {
        $rawBytes = strlen($rawBody);
        try {
            $request = json_decode($rawBody, false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new GatewayException(400, 'request body is not valid JSON');
        }
        if (!$request instanceof stdClass) {
            throw new GatewayException(400, 'request body must be a JSON object');
        }

        $verified = $this->verifier->verify($request, $rawBytes, $nowMs);

        // Sybil/spam gate: only bonded publishers spend protocol storage.
        if (!$this->registry->isRegistered($verified->publisher)) {
            throw new GatewayException(403, 'publisher is not registered');
        }

        // Fast replay reject (avoids a wasted upload on the common case).
        if ($this->broker->nonceSeen($verified->nonce)) {
            throw new GatewayException(409, 'nonce already used');
        }

        // Per-publisher rate limit over the trailing hour.
        if ($this->broker->countRecentUploads($verified->publisher, 3600) >= $this->quotaPerHour) {
            throw new GatewayException(429, 'upload quota exceeded');
        }

        // Pin the public envelope (canonical bytes) and the encrypted context.
        $evidenceCid = $this->store->store(CanonicalJson::encode($verified->envelope));
        $contextCid = $verified->encryptedContext !== null
            ? $this->store->store($verified->encryptedContext)
            : null;

        // Authoritative replay guard: the UNIQUE nonce. null = concurrent dup.
        $id = $this->broker->record($verified->publisher, $verified->nonce, $evidenceCid, $contextCid);
        if ($id === null) {
            throw new GatewayException(409, 'nonce already used');
        }

        $response = ['evidenceCid' => $evidenceCid];
        if ($contextCid !== null) {
            $response['contextCid'] = $contextCid;
        }
        return $response;
    }
}
