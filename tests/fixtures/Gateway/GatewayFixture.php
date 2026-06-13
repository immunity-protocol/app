<?php

declare(strict_types=1);

namespace Tests\Fixtures\Gateway;

use stdClass;

/**
 * Loads the SDK-produced gateway request fixture (tests/fixtures/gateway/
 * request.fixture.json — see generate.cjs). Used to prove the PHP gateway's
 * canonical-JSON + EIP-191 implementation matches the shipped SDK byte-for-byte.
 */
final class GatewayFixture
{
    public static function load(): stdClass
    {
        $path = __DIR__ . '/request.fixture.json';
        $json = file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException("missing gateway fixture at $path");
        }
        return json_decode($json, false, 64, JSON_THROW_ON_ERROR);
    }

    /**
     * Build a complete GatewayRequestV1 string for the given variant
     * ('withContext' | 'noContext'), overriding any top-level fields.
     */
    public static function requestJson(string $variant = 'withContext', array $overrides = []): string
    {
        $fx = self::load();
        $v = $fx->{$variant};
        $request = [
            'schema' => 'immunity/gateway-request/v1',
            'publisher' => $fx->publisher,
            'payloadHash' => $v->payloadHash,
            'timestamp' => 1749772800000,
            'nonce' => bin2hex(random_bytes(16)),
            'signature' => $v->signature,
            'payload' => $v->payload,
        ];
        foreach ($overrides as $key => $value) {
            $request[$key] = $value;
        }
        return json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
