<?php

declare(strict_types=1);

namespace Tests\Unit\Models\Gateway;

use App\Models\Gateway\Eip191Verifier;
use Tests\Fixtures\Gateway\GatewayFixture;
use Tests\TestCase;

/**
 * EIP-191 recovery against a REAL SDK-produced signature (personal_sign over
 * the 32 raw bytes of payloadHash).
 */
final class Eip191VerifierTest extends TestCase
{
    public function testRecoversTheSdkSigner(): void
    {
        $fx = GatewayFixture::load();
        $recovered = Eip191Verifier::recover($fx->withContext->payloadHash, $fx->withContext->signature);
        $this->assertSame(strtolower($fx->publisher), strtolower($recovered));
    }

    public function testVerifyTrueForMatchingPublisher(): void
    {
        $fx = GatewayFixture::load();
        $this->assertTrue(Eip191Verifier::verify($fx->withContext->payloadHash, $fx->withContext->signature, $fx->publisher));
    }

    public function testVerifyFalseForWrongAddress(): void
    {
        $fx = GatewayFixture::load();
        $this->assertFalse(Eip191Verifier::verify(
            $fx->withContext->payloadHash,
            $fx->withContext->signature,
            '0x0000000000000000000000000000000000000001',
        ));
    }

    public function testVerifyFalseForTamperedHash(): void
    {
        $fx = GatewayFixture::load();
        $tampered = '0x' . str_repeat('ab', 32);
        $this->assertFalse(Eip191Verifier::verify($tampered, $fx->withContext->signature, $fx->publisher));
    }

    public function testVerifyFalseForMalformedSignature(): void
    {
        $fx = GatewayFixture::load();
        $this->assertFalse(Eip191Verifier::verify($fx->withContext->payloadHash, '0xdeadbeef', $fx->publisher));
    }
}
