<?php

declare(strict_types=1);

namespace Tests\Unit\Models\Gateway;

use App\Models\Gateway\CanonicalJson;
use Tests\Fixtures\Gateway\GatewayFixture;
use Tests\TestCase;

/**
 * Proves the PHP canonical-JSON + payloadHash match the SHIPPED SDK
 * byte-for-byte (fixture produced by tests/fixtures/Gateway/generate.cjs using
 * the real `canonicalJson` from immunity-sdk). One mismatched byte = every
 * upload fails signature verification.
 */
final class CanonicalJsonTest extends TestCase
{
    public function testCanonicalJsonMatchesSdkByteForByteWithContext(): void
    {
        $fx = GatewayFixture::load();
        $this->assertSame($fx->withContext->canonicalJson, CanonicalJson::encode($fx->withContext->payload));
    }

    public function testCanonicalJsonMatchesSdkByteForByteNoContext(): void
    {
        $fx = GatewayFixture::load();
        $this->assertSame($fx->noContext->canonicalJson, CanonicalJson::encode($fx->noContext->payload));
    }

    public function testPayloadHashMatchesSdk(): void
    {
        $fx = GatewayFixture::load();
        $this->assertSame(
            strtolower($fx->withContext->payloadHash),
            strtolower(CanonicalJson::payloadHash($fx->withContext->payload)),
        );
        $this->assertSame(
            strtolower($fx->noContext->payloadHash),
            strtolower(CanonicalJson::payloadHash($fx->noContext->payload)),
        );
    }

    public function testSortsKeysRecursivelyAndPreservesArrayOrder(): void
    {
        $value = (object) [
            'b' => 1,
            'a' => (object) ['z' => 1, 'y' => 2],
            'list' => [3, 1, 2],
        ];
        $this->assertSame('{"a":{"y":2,"z":1},"b":1,"list":[3,1,2]}', CanonicalJson::encode($value));
    }

    public function testDoesNotEscapeSlashAndKeepsRawUnicode(): void
    {
        $value = (object) ['p' => 'a/b', 'u' => 'é'];
        $this->assertSame('{"p":"a/b","u":"é"}', CanonicalJson::encode($value));
    }
}
