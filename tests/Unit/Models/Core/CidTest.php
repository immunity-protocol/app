<?php

declare(strict_types=1);

namespace Tests\Unit\Models\Core;

use App\Models\Core\Cid;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The CID contract: the on-chain bytes32 is the CIDv0/dag-pb sha2-256 multihash
 * digest, and base58btc(0x12 ‖ 0x20 ‖ digest) reconstructs the `Qm…` fetch CID.
 * These vectors must match the gateway (docs/gateway-cid-verdict.md) and the
 * SDK (storage/cid.ts) exactly, or evidence will not resolve.
 */
final class CidTest extends TestCase
{
    /** The pinned canonical example from docs/gateway-cid-verdict.md. */
    private const PINNED_CID = 'QmXE7xhPkmfF4tdXmwSh6ZUWydKn6fmaE9FVasfzWUxou2';
    private const PINNED_HEX = '0x840cee1be318d9428e10f2af7dae0147efd1eede3a4d32610e7780a7a09479e9';

    public function testPinnedCidDecodesToExpectedDigest(): void
    {
        self::assertSame(self::PINNED_HEX, Cid::cidToHex32(self::PINNED_CID));
    }

    public function testDigestReconstructsPinnedCid(): void
    {
        self::assertSame(self::PINNED_CID, Cid::hex32ToCid(self::PINNED_HEX));
    }

    public function testRawDigestRoundTrip(): void
    {
        $digest = Cid::cidV0ToDigest(self::PINNED_CID);
        self::assertSame(32, strlen($digest));
        self::assertSame(self::PINNED_CID, Cid::digestToCidV0($digest));
    }

    public function testHexRoundTripIsIdentity(): void
    {
        $cid = Cid::hex32ToCid(self::PINNED_HEX);
        self::assertSame(self::PINNED_HEX, Cid::cidToHex32($cid));
    }

    public function testZeroDigestRoundTrips(): void
    {
        $zero = str_repeat("\0", 32);
        $cid = Cid::digestToCidV0($zero);
        self::assertStringStartsWith('Qm', $cid);
        self::assertSame($zero, Cid::cidV0ToDigest($cid));
    }

    public function testRejectsCidV1(): void
    {
        $this->expectException(RuntimeException::class);
        Cid::cidV0ToDigest('bafkreigh2akiscaildcqabsyg3dfr6chu3fgpregiymsck7e7aqa4s52zy');
    }

    public function testRejectsWrongLengthDigest(): void
    {
        $this->expectException(RuntimeException::class);
        Cid::digestToCidV0(str_repeat("\0", 31));
    }
}
