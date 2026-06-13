<?php

declare(strict_types=1);

namespace Tests\Unit\Models\Gateway;

use App\Models\Gateway\GatewayException;
use App\Models\Gateway\GatewayRequestVerifier;
use Tests\Fixtures\Gateway\GatewayFixture;
use Tests\TestCase;

/**
 * Fail-closed verification of GatewayRequestV1: structure, payloadHash recompute,
 * EIP-191 recovery, freshness window, size cap.
 */
final class GatewayRequestVerifierTest extends TestCase
{
    private const int NOW_MS = 1749772800000;

    private function decode(string $json): \stdClass
    {
        return json_decode($json, false, 64, JSON_THROW_ON_ERROR);
    }

    public function testAcceptsAValidRequestWithContext(): void
    {
        $json = GatewayFixture::requestJson('withContext', ['timestamp' => self::NOW_MS]);
        $verified = (new GatewayRequestVerifier())->verify($this->decode($json), strlen($json), self::NOW_MS);

        $this->assertSame('0xf39fd6e51aad88f6f4ce6ab8827279cfffb92266', $verified->publisher);
        $this->assertNotNull($verified->encryptedContext);
    }

    public function testAcceptsAValidRequestWithoutContext(): void
    {
        $json = GatewayFixture::requestJson('noContext', ['timestamp' => self::NOW_MS]);
        $verified = (new GatewayRequestVerifier())->verify($this->decode($json), strlen($json), self::NOW_MS);

        $this->assertNull($verified->encryptedContext);
    }

    public function testRejectsStaleTimestamp(): void
    {
        $json = GatewayFixture::requestJson('withContext', ['timestamp' => self::NOW_MS]);
        $this->expectRejection(400, fn () => (new GatewayRequestVerifier())
            ->verify($this->decode($json), strlen($json), self::NOW_MS + 6 * 60 * 1000));
    }

    public function testRejectsTamperedSignature(): void
    {
        $json = GatewayFixture::requestJson('withContext', [
            'timestamp' => self::NOW_MS,
            'signature' => '0x' . str_repeat('00', 65),
        ]);
        $this->expectRejection(401, fn () => (new GatewayRequestVerifier())
            ->verify($this->decode($json), strlen($json), self::NOW_MS));
    }

    public function testRejectsPayloadHashMismatch(): void
    {
        // A valid signature over a different hash, but the payload re-hashes elsewhere.
        $json = GatewayFixture::requestJson('withContext', [
            'timestamp' => self::NOW_MS,
            'payloadHash' => '0x' . str_repeat('11', 32),
        ]);
        $this->expectRejection(400, fn () => (new GatewayRequestVerifier())
            ->verify($this->decode($json), strlen($json), self::NOW_MS));
    }

    public function testRejectsOversizeBody(): void
    {
        $json = GatewayFixture::requestJson('withContext', ['timestamp' => self::NOW_MS]);
        $this->expectRejection(413, fn () => (new GatewayRequestVerifier())
            ->verify($this->decode($json), 300_000, self::NOW_MS));
    }

    public function testRejectsBadSchema(): void
    {
        $json = GatewayFixture::requestJson('withContext', ['timestamp' => self::NOW_MS, 'schema' => 'nope']);
        $this->expectRejection(400, fn () => (new GatewayRequestVerifier())
            ->verify($this->decode($json), strlen($json), self::NOW_MS));
    }

    public function testRejectsMalformedPublisher(): void
    {
        $json = GatewayFixture::requestJson('withContext', ['timestamp' => self::NOW_MS, 'publisher' => '0x1234']);
        $this->expectRejection(400, fn () => (new GatewayRequestVerifier())
            ->verify($this->decode($json), strlen($json), self::NOW_MS));
    }

    private function expectRejection(int $status, callable $fn): void
    {
        try {
            $fn();
            $this->fail("expected GatewayException $status");
        } catch (GatewayException $e) {
            $this->assertSame($status, $e->httpStatus, $e->getMessage());
        }
    }
}
