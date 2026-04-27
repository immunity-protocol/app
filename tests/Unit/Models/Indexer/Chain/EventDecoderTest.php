<?php

declare(strict_types=1);

namespace Tests\Unit\Models\Indexer\Chain;

use App\Models\Indexer\Chain\EventDecoder;
use App\Models\Indexer\Chain\RegistryAbi;
use Tests\TestCase;

final class EventDecoderTest extends TestCase
{
    /** Address used as a stable test fixture; not a "real" config value. */
    private const REGISTRY_ADDRESS_FIXTURE = '0x45Ee45Ca358b3fc9B1b245a8f1c1C3128caC8e48';

    private RegistryAbi $abi;
    private EventDecoder $decoder;

    protected function setUp(): void
    {
        $this->abi = new RegistryAbi();
        $this->decoder = new EventDecoder($this->abi);
    }

    public function testTopicForKnownEventIsStable(): void
    {
        // keccak256("AntibodyPublished(bytes32,uint32,address,uint8,uint8,uint8,uint8,uint8,address,bytes32,bytes32,bytes32,bytes32,bytes32,uint256,uint64,uint64,uint64,bool)")
        $expected = '0x8006f18d06959fa3d8e542b17bbfc9dad375f9784fc3c8e7b70282b3d2ffb4e9';
        $event = $this->abi->eventByName('AntibodyPublished');
        self::assertNotNull($event);
        self::assertSame($expected, RegistryAbi::topicForEvent($event));
    }

    public function testTopic0ToEventLookup(): void
    {
        $found = $this->abi->eventByTopic('0x8006f18d06959fa3d8e542b17bbfc9dad375f9784fc3c8e7b70282b3d2ffb4e9');
        self::assertNotNull($found);
        self::assertSame('AntibodyPublished', $found['name']);
    }

    public function testUnknownTopicYieldsNull(): void
    {
        $log = [
            'topics' => ['0x' . str_repeat('a', 64)],
            'data'   => '0x',
            'blockNumber'    => '0x10',
            'transactionHash' => '0x' . str_repeat('1', 64),
            'logIndex'       => '0x0',
            'address'        => '0x0',
        ];
        self::assertNull($this->decoder->decode($log));
    }

    public function testDecodeAntibodySlashed(): void
    {
        // AntibodySlashed(indexed bytes32 keccakId, indexed address publisher, uint256 stakeAmount)
        $event = $this->abi->eventByName('AntibodySlashed');
        $topic0 = RegistryAbi::topicForEvent($event);

        $keccakId = '0x' . str_repeat('ab', 32);
        $publisherTopic = '0x' . str_pad(str_repeat('0', 0) . 'b30af804fd19565e6bcbfdced944fdf654e585d9', 64, '0', STR_PAD_LEFT);
        $stake = 12345678;
        $stakeWord = str_pad(dechex($stake), 64, '0', STR_PAD_LEFT);

        $log = [
            'topics' => [
                $topic0,
                $keccakId,
                $publisherTopic,
            ],
            'data'   => '0x' . $stakeWord,
            'blockNumber'    => '0x' . dechex(29900000),
            'transactionHash' => '0x' . str_repeat('1', 64),
            'logIndex'       => '0x5',
            'address'        => self::REGISTRY_ADDRESS_FIXTURE,
        ];
        $decoded = $this->decoder->decode($log);
        self::assertNotNull($decoded);
        self::assertSame('AntibodySlashed', $decoded['event']);
        self::assertSame($keccakId, $decoded['args']['keccakId']);
        self::assertSame('0xb30af804fd19565e6bcbfdced944fdf654e585d9', $decoded['args']['publisher']);
        self::assertSame((string) $stake, $decoded['args']['stakeAmount']);
        self::assertSame(29900000, $decoded['blockNumber']);
        self::assertSame(5, $decoded['logIndex']);
    }

    public function testDecodeCheckSettledMixedIndexed(): void
    {
        // CheckSettled(indexed address agent, indexed bytes32 antibodyId,
        //              indexed address tokenAddress, bool wasMatch, uint256 fee,
        //              uint256 originChainId, uint256 tokenAmount, uint64 timestamp)
        $event = $this->abi->eventByName('CheckSettled');
        $topic0 = RegistryAbi::topicForEvent($event);

        $agent = '0xc11376d56e2ab8dbbd3b2fb36a2a0b2e62ecf600';
        $agentTopic = '0x' . str_pad(substr($agent, 2), 64, '0', STR_PAD_LEFT);
        $antibodyId = '0x' . str_repeat('cd', 32);
        $tokenAddress = '0xa0b86991c6218b36c1d19d4a2e9eb0ce3606eb48';
        $tokenTopic = '0x' . str_pad(substr($tokenAddress, 2), 64, '0', STR_PAD_LEFT);

        $wasMatch = 1;
        $fee = 200;
        $originChainId = 1;
        $tokenAmount = 1_500_000_000;
        $ts = 1745000000;

        $data =
            str_pad(dechex($wasMatch), 64, '0', STR_PAD_LEFT)
          . str_pad(dechex($fee), 64, '0', STR_PAD_LEFT)
          . str_pad(dechex($originChainId), 64, '0', STR_PAD_LEFT)
          . str_pad(dechex($tokenAmount), 64, '0', STR_PAD_LEFT)
          . str_pad(dechex($ts), 64, '0', STR_PAD_LEFT);

        $log = [
            'topics' => [$topic0, $agentTopic, $antibodyId, $tokenTopic],
            'data'   => '0x' . $data,
            'blockNumber'    => '0x1',
            'transactionHash' => '0x' . str_repeat('2', 64),
            'logIndex'       => '0x0',
            'address'        => self::REGISTRY_ADDRESS_FIXTURE,
        ];
        $decoded = $this->decoder->decode($log);
        self::assertNotNull($decoded);
        self::assertSame('CheckSettled', $decoded['event']);
        self::assertSame($agent, $decoded['args']['agent']);
        self::assertSame($antibodyId, $decoded['args']['antibodyId']);
        self::assertSame($tokenAddress, $decoded['args']['tokenAddress']);
        self::assertTrue($decoded['args']['wasMatch']);
        self::assertSame((string) $fee, $decoded['args']['fee']);
        self::assertSame((string) $originChainId, $decoded['args']['originChainId']);
        self::assertSame((string) $tokenAmount, $decoded['args']['tokenAmount']);
        self::assertSame((string) $ts, $decoded['args']['timestamp']);
    }

    public function testDecodeStakeSweptUnsignedScalar(): void
    {
        // StakeSwept(indexed address sweeper, uint256 numReleased, uint256 bountyPaid)
        $event = $this->abi->eventByName('StakeSwept');
        $topic0 = RegistryAbi::topicForEvent($event);

        $sweeper = '0x1111111111111111111111111111111111111111';
        $sweeperTopic = '0x' . str_pad(substr($sweeper, 2), 64, '0', STR_PAD_LEFT);
        $numReleased = 7;
        $bounty = 50000;

        $data =
            str_pad(dechex($numReleased), 64, '0', STR_PAD_LEFT)
          . str_pad(dechex($bounty), 64, '0', STR_PAD_LEFT);

        $log = [
            'topics' => [$topic0, $sweeperTopic],
            'data'   => '0x' . $data,
            'blockNumber'    => '0x2',
            'transactionHash' => '0x' . str_repeat('3', 64),
            'logIndex'       => '0x1',
            'address'        => self::REGISTRY_ADDRESS_FIXTURE,
        ];
        $decoded = $this->decoder->decode($log);
        self::assertNotNull($decoded);
        self::assertSame('StakeSwept', $decoded['event']);
        self::assertSame($sweeper, $decoded['args']['sweeper']);
        self::assertSame((string) $numReleased, $decoded['args']['numReleased']);
        self::assertSame((string) $bounty, $decoded['args']['bountyPaid']);
    }

    public function testDecodeWordHandlesLargeUint256AsString(): void
    {
        $hugeHex = str_repeat('f', 64);
        $result = EventDecoder::decodeWord('uint256', $hugeHex);
        self::assertIsString($result);
        self::assertSame('115792089237316195423570985008687907853269984665640564039457584007913129639935', $result);
    }
}
