<?php

declare(strict_types=1);

namespace Tests\Unit\Models\Indexer\Pricing;

use App\Models\Indexer\Brokers\TokenPriceCacheBroker;
use App\Models\Indexer\Pricing\MoralisPriceService;
use Moralis\MoralisService;
use Moralis\MoralisToken;
use PHPUnit\Framework\TestCase as BaseTestCase;
use RuntimeException;
use stdClass;

final class MoralisPriceServiceTest extends BaseTestCase
{
    private const USDC_ETH = '0xa0b86991c6218b36c1d19d4a2e9eb0ce3606eb48';

    private function mkToken(float $usdPrice = 1.0, int $decimals = 6, string $symbol = 'USDC'): MoralisToken
    {
        $std = new stdClass();
        $std->tokenName = 'USD Coin';
        $std->tokenSymbol = $symbol;
        $std->tokenDecimals = $decimals;
        $std->usdPrice = $usdPrice;
        $std->usdPriceFormatted = (string) $usdPrice;
        $std->tokenAddress = self::USDC_ETH;
        return MoralisToken::fromStd($std);
    }

    private function freshCacheRow(float $usdPrice = 1.0, int $decimals = 6): stdClass
    {
        $row = new stdClass();
        $row->token_address = '\\x' . substr(self::USDC_ETH, 2);
        $row->chain_id = 1;
        $row->usd_price = (string) $usdPrice;
        $row->decimals = $decimals;
        $row->symbol = 'USDC';
        $row->fetched_at = date('Y-m-d H:i:sP');
        return $row;
    }

    private function staleCacheRow(): stdClass
    {
        $row = $this->freshCacheRow();
        $row->fetched_at = date('Y-m-d H:i:sP', time() - 600); // 10 minutes ago
        return $row;
    }

    public function testZeroAmountShortCircuitsToZero(): void
    {
        $moralis = $this->createMock(MoralisService::class);
        $moralis->expects(self::never())->method('fetchToken');
        $broker = $this->createMock(TokenPriceCacheBroker::class);
        $broker->expects(self::never())->method('find');

        $svc = new MoralisPriceService($moralis, $broker);
        self::assertSame('0', $svc->priceUsd(self::USDC_ETH, 1, '0'));
    }

    public function testUnsupportedChainReturnsNull(): void
    {
        $moralis = $this->createMock(MoralisService::class);
        $moralis->expects(self::never())->method('fetchToken');
        $broker = $this->createMock(TokenPriceCacheBroker::class);

        $svc = new MoralisPriceService($moralis, $broker);
        // 16602 is 0G Galileo — not in the chain map.
        self::assertNull($svc->priceUsd(self::USDC_ETH, 16602, '1000000'));
    }

    public function testCacheHitSkipsMoralisAndComputesValue(): void
    {
        $moralis = $this->createMock(MoralisService::class);
        $moralis->expects(self::never())->method('fetchToken');

        $broker = $this->createMock(TokenPriceCacheBroker::class);
        $broker->expects(self::once())
            ->method('find')
            ->with(strtolower(self::USDC_ETH), 1)
            ->willReturn($this->freshCacheRow(1.0, 6));
        $broker->expects(self::never())->method('upsert');

        $svc = new MoralisPriceService($moralis, $broker);
        // 1,500,000 base units of USDC (6 decimals) = 1.5 USDC * 1.0 USD = 1.5
        $value = $svc->priceUsd(self::USDC_ETH, 1, '1500000');
        self::assertNotNull($value);
        self::assertSame(1.5, (float) $value);
    }

    public function testCacheMissCallsMoralisAndUpserts(): void
    {
        $moralis = $this->createMock(MoralisService::class);
        $moralis->expects(self::once())
            ->method('fetchToken')
            ->with(strtolower(self::USDC_ETH), 'eth')
            ->willReturn($this->mkToken(usdPrice: 1.0, decimals: 6));

        $broker = $this->createMock(TokenPriceCacheBroker::class);
        $broker->expects(self::once())->method('find')->willReturn(null);
        $broker->expects(self::once())
            ->method('upsert')
            ->with(strtolower(self::USDC_ETH), 1, 1.0, 6, 'USDC');

        $svc = new MoralisPriceService($moralis, $broker);
        $value = $svc->priceUsd(self::USDC_ETH, 1, '2500000');
        self::assertSame(2.5, (float) $value);
    }

    public function testStaleCacheRefreshesViaMoralis(): void
    {
        $moralis = $this->createMock(MoralisService::class);
        $moralis->expects(self::once())
            ->method('fetchToken')
            ->willReturn($this->mkToken(usdPrice: 2.0, decimals: 6));

        $broker = $this->createMock(TokenPriceCacheBroker::class);
        $broker->expects(self::once())->method('find')->willReturn($this->staleCacheRow());
        $broker->expects(self::once())->method('upsert');

        $svc = new MoralisPriceService($moralis, $broker);
        $value = $svc->priceUsd(self::USDC_ETH, 1, '1000000');
        self::assertSame(2.0, (float) $value);
    }

    public function testMoralisFailureFallsBackToStaleCache(): void
    {
        $moralis = $this->createMock(MoralisService::class);
        $moralis->expects(self::once())
            ->method('fetchToken')
            ->willThrowException(new RuntimeException('Moralis 429 Too Many Requests'));

        $broker = $this->createMock(TokenPriceCacheBroker::class);
        $broker->expects(self::once())->method('find')->willReturn($this->staleCacheRow());

        $svc = new MoralisPriceService($moralis, $broker);
        $value = $svc->priceUsd(self::USDC_ETH, 1, '1000000');
        // Stale cache had usd_price 1.0 → 1 USDC value.
        self::assertSame(1.0, (float) $value);
    }

    public function testMoralisFailureWithNoCacheReturnsNull(): void
    {
        $moralis = $this->createMock(MoralisService::class);
        $moralis->expects(self::once())
            ->method('fetchToken')
            ->willThrowException(new RuntimeException('Moralis down'));

        $broker = $this->createMock(TokenPriceCacheBroker::class);
        $broker->expects(self::once())->method('find')->willReturn(null);

        $svc = new MoralisPriceService($moralis, $broker);
        self::assertNull($svc->priceUsd(self::USDC_ETH, 1, '1000000'));
    }

    public function testNativeTokenMapsToWrappedAddress(): void
    {
        $moralis = $this->createMock(MoralisService::class);
        $moralis->expects(self::once())
            ->method('fetchToken')
            // WETH on mainnet
            ->with('0xc02aaa39b223fe8d0a0e5c4f27ead9083c756cc2', 'eth')
            ->willReturn($this->mkToken(usdPrice: 2000.0, decimals: 18, symbol: 'WETH'));

        $broker = $this->createMock(TokenPriceCacheBroker::class);
        $broker->expects(self::once())
            ->method('find')
            ->with('0xc02aaa39b223fe8d0a0e5c4f27ead9083c756cc2', 1)
            ->willReturn(null);
        $broker->expects(self::once())->method('upsert');

        $svc = new MoralisPriceService($moralis, $broker);
        // 0.5 ETH = 5e17 wei. At $2000/ETH → $1000.
        $value = $svc->priceUsd('0x0000000000000000000000000000000000000000', 1, '500000000000000000');
        self::assertSame(1000.0, (float) $value);
    }

    public function testHintDecimalsOverrideTokenDecimals(): void
    {
        $moralis = $this->createMock(MoralisService::class);
        $moralis->expects(self::once())
            ->method('fetchToken')
            // Moralis returns 18 decimals, but caller hints 6.
            ->willReturn($this->mkToken(usdPrice: 1.0, decimals: 18));

        $broker = $this->createMock(TokenPriceCacheBroker::class);
        $broker->expects(self::once())->method('find')->willReturn(null);
        $broker->expects(self::once())->method('upsert');

        $svc = new MoralisPriceService($moralis, $broker);
        $value = $svc->priceUsd(self::USDC_ETH, 1, '1000000', hintDecimals: 6);
        self::assertSame(1.0, (float) $value);
    }
}
