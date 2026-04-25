<?php

declare(strict_types=1);

namespace Tests\Integration\Models\Domain\Publisher;

use App\Models\Domain\Publisher\PublisherBroker;
use Tests\IntegrationTestCase;

final class PublisherBrokerTest extends IntegrationTestCase
{
    private PublisherBroker $broker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->broker = new PublisherBroker($this->db);
    }

    public function testUpsertInsertsThenUpdates(): void
    {
        $hex = str_repeat('aa', 20);
        $this->broker->upsert($this->fixture($hex, ens: 'huntress.eth', published: 5));
        $this->broker->upsert($this->fixture($hex, ens: 'huntress.eth', published: 12));

        $found = $this->broker->findByAddress($hex);
        $this->assertNotNull($found);
        $this->assertSame('huntress.eth', $found->ens);
        $this->assertSame(12, $found->antibodiesPublished);
    }

    public function testFindTopByAntibodies(): void
    {
        $this->broker->upsert($this->fixture(str_repeat('11', 20), ens: 'a.eth', published: 1));
        $this->broker->upsert($this->fixture(str_repeat('22', 20), ens: 'b.eth', published: 50));
        $this->broker->upsert($this->fixture(str_repeat('33', 20), ens: 'c.eth', published: 10));

        $top = $this->broker->findTopByAntibodies(2);

        $this->assertCount(2, $top);
        $this->assertSame('b.eth', $top[0]->ens);
        $this->assertSame('c.eth', $top[1]->ens);
    }

    public function testCountAll(): void
    {
        $this->assertSame(0, $this->broker->countAll());
        $this->broker->upsert($this->fixture(str_repeat('11', 20)));
        $this->broker->upsert($this->fixture(str_repeat('22', 20)));
        $this->assertSame(2, $this->broker->countAll());
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(
        string $addressHex,
        ?string $ens = null,
        int $published = 0,
    ): array {
        return [
            'address'              => '\\x' . $addressHex,
            'ens'                  => $ens,
            'antibodies_published' => $published,
            'successful_blocks'    => 0,
            'total_earned_usdc'    => '0',
            'total_staked_usdc'    => '0',
        ];
    }
}
