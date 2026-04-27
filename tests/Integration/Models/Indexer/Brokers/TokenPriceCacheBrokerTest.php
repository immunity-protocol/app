<?php

declare(strict_types=1);

namespace Tests\Integration\Models\Indexer\Brokers;

use App\Models\Indexer\Brokers\TokenPriceCacheBroker;
use Tests\IntegrationTestCase;

final class TokenPriceCacheBrokerTest extends IntegrationTestCase
{
    private TokenPriceCacheBroker $broker;

    private const USDC = '0xa0b86991c6218b36c1d19d4a2e9eb0ce3606eb48';
    private const WETH = '0xc02aaa39b223fe8d0a0e5c4f27ead9083c756cc2';

    protected function setUp(): void
    {
        parent::setUp();
        $this->broker = new TokenPriceCacheBroker($this->db);
    }

    public function testFindReturnsNullWhenNoEntry(): void
    {
        self::assertNull($this->broker->find(self::USDC, 1));
    }

    public function testUpsertInsertsAndFindReturns(): void
    {
        $this->broker->upsert(self::USDC, 1, 1.0001, 6, 'USDC');
        $row = $this->broker->find(self::USDC, 1);
        self::assertNotNull($row);
        self::assertSame(6, (int) $row->decimals);
        self::assertSame('USDC', (string) $row->symbol);
        self::assertSame('1.000100000000000000', (string) $row->usd_price);
    }

    public function testUpsertOverwritesPriceAndAdvancesFetchedAt(): void
    {
        $this->broker->upsert(self::USDC, 1, 1.0, 6, 'USDC');
        $first = $this->broker->find(self::USDC, 1);

        // Force a measurable timestamp gap.
        usleep(10_000);

        $this->broker->upsert(self::USDC, 1, 1.5, 6, 'USDC');
        $second = $this->broker->find(self::USDC, 1);

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame('1.500000000000000000', (string) $second->usd_price);
        self::assertGreaterThanOrEqual((string) $first->fetched_at, (string) $second->fetched_at);
    }

    public function testEntriesAreScopedByChainId(): void
    {
        $this->broker->upsert(self::USDC, 1, 1.0, 6, 'USDC');
        $this->broker->upsert(self::USDC, 8453, 0.99, 6, 'USDbC');

        $eth = $this->broker->find(self::USDC, 1);
        $base = $this->broker->find(self::USDC, 8453);

        self::assertNotNull($eth);
        self::assertNotNull($base);
        self::assertSame('USDC', (string) $eth->symbol);
        self::assertSame('USDbC', (string) $base->symbol);
    }

    public function testCaseInsensitiveAddressLookup(): void
    {
        $this->broker->upsert(self::WETH, 1, 2000.0, 18, 'WETH');
        // Mixed-case lookup should find the same entry.
        $row = $this->broker->find(strtoupper(self::WETH), 1);
        self::assertNotNull($row);
        self::assertSame(18, (int) $row->decimals);
    }
}
