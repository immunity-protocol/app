<?php

declare(strict_types=1);

namespace Tests\Unit\Models\Indexer\Chain;

use App\Models\Indexer\Chain\BaseChainAbi;
use App\Models\Indexer\Chain\ContractAbi;
use App\Models\Indexer\Chain\EventDecoder;
use Tests\TestCase;

final class EventDecoderTest extends TestCase
{
    // Stable fixture addresses, one per contract (not real config values).
    private const REGISTRY    = '0x1111111111111111111111111111111111111111';
    private const REPUTATION  = '0x2222222222222222222222222222222222222222';
    private const REGISTRAR   = '0x3333333333333333333333333333333333333333';
    private const CHALLENGE   = '0x4444444444444444444444444444444444444444';

    private BaseChainAbi $abi;
    private EventDecoder $decoder;

    protected function setUp(): void
    {
        $this->abi = BaseChainAbi::fromContracts([
            'Registry'           => [self::REGISTRY,   'ImmunityRegistry.json'],
            'Reputation'         => [self::REPUTATION, 'Reputation.json'],
            'PublisherRegistrar' => [self::REGISTRAR,  'PublisherRegistrar.json'],
            'ChallengeManager'   => [self::CHALLENGE,  'ChallengeManager.json'],
        ]);
        $this->decoder = new EventDecoder($this->abi);
    }

    private static function topicFor(ContractAbi $c, string $event): string
    {
        return ContractAbi::topicForEvent($c->eventByName($event));
    }

    private static function word(int|string $value): string
    {
        $hex = is_int($value) ? dechex($value) : $value;
        return str_pad($hex, 64, '0', STR_PAD_LEFT);
    }

    private static function addrTopic(string $address): string
    {
        return '0x' . str_pad(substr($address, 2), 64, '0', STR_PAD_LEFT);
    }

    public function testTopicLookupIsStableRoundTrip(): void
    {
        $registry = $this->abi->contractAt(self::REGISTRY);
        $topic = self::topicFor($registry, 'Published');
        self::assertSame('Published', $registry->eventByTopic($topic)['name']);
    }

    public function testLogFromUnwatchedAddressYieldsNull(): void
    {
        $registry = $this->abi->contractAt(self::REGISTRY);
        $log = [
            'topics'          => [self::topicFor($registry, 'Retired')],
            'data'            => '0x',
            'blockNumber'     => '0x1',
            'transactionHash' => '0x' . str_repeat('1', 64),
            'logIndex'        => '0x0',
            'address'         => '0x9999999999999999999999999999999999999999',
        ];
        self::assertNull($this->decoder->decode($log));
    }

    public function testDecodeRegistrySlashed(): void
    {
        $registry = $this->abi->contractAt(self::REGISTRY);
        $keccak = '0x' . str_repeat('ab', 32);
        $publisher = '0xb30af804fd19565e6bcbfdced944fdf654e585d9';
        $challenger = '0xc11376d56e2ab8dbbd3b2fb36a2a0b2e62ecf600';

        $log = [
            'topics' => [
                self::topicFor($registry, 'Slashed'),
                $keccak,
                self::addrTopic($publisher),
                self::addrTopic($challenger),
            ],
            'data'            => '0x' . self::word(12345678) . self::word(500),
            'blockNumber'     => '0x' . dechex(42781200),
            'transactionHash' => '0x' . str_repeat('1', 64),
            'logIndex'        => '0x5',
            'address'         => self::REGISTRY,
        ];
        $decoded = $this->decoder->decode($log);
        self::assertSame('Registry', $decoded['contract']);
        self::assertSame('Slashed', $decoded['event']);
        self::assertSame($keccak, $decoded['args']['keccakId']);
        self::assertSame($publisher, $decoded['args']['publisher']);
        self::assertSame($challenger, $decoded['args']['challenger']);
        self::assertSame('12345678', $decoded['args']['bondForfeited']);
        self::assertSame('500', $decoded['args']['escrowClawedBack']);
        self::assertSame(42781200, $decoded['blockNumber']);
        self::assertSame(5, $decoded['logIndex']);
    }

    /** Same event name on two contracts must resolve to the right ABI by address. */
    public function testSameNameDifferentContractDisambiguatedByAddress(): void
    {
        $registry = $this->abi->contractAt(self::REGISTRY);
        $reputation = $this->abi->contractAt(self::REPUTATION);
        $keccak = '0x' . str_repeat('cd', 32);
        $publisher = '0xb30af804fd19565e6bcbfdced944fdf654e585d9';

        // Registry.Matured(keccakId, publisher, releasedFees, maturedAt)
        $regLog = [
            'topics'          => [self::topicFor($registry, 'Matured'), $keccak, self::addrTopic($publisher)],
            'data'            => '0x' . self::word(900) . self::word(1745000000),
            'blockNumber'     => '0x1', 'transactionHash' => '0x' . str_repeat('1', 64),
            'logIndex'        => '0x0', 'address' => self::REGISTRY,
        ];
        $reg = $this->decoder->decode($regLog);
        self::assertSame('Registry', $reg['contract']);
        self::assertSame('900', $reg['args']['releasedFees']);

        // Reputation.Matured(publisher, newScore)
        $repLog = [
            'topics'          => [self::topicFor($reputation, 'Matured'), self::addrTopic($publisher)],
            'data'            => '0x' . self::word(125),
            'blockNumber'     => '0x1', 'transactionHash' => '0x' . str_repeat('2', 64),
            'logIndex'        => '0x1', 'address' => self::REPUTATION,
        ];
        $rep = $this->decoder->decode($repLog);
        self::assertSame('Reputation', $rep['contract']);
        self::assertSame('125', $rep['args']['newScore']);
    }

    public function testDecodeRegisteredDynamicString(): void
    {
        $registrar = $this->abi->contractAt(self::REGISTRAR);
        $publisher = '0xb30af804fd19565e6bcbfdced944fdf654e585d9';
        $node = '0x' . str_repeat('11', 32);
        $label = 'genesis';

        // data tuple: [label offset = 0x40][bond] [label len][label bytes]
        $data = self::word(0x40) . self::word(2_000_000)
              . self::word(strlen($label)) . str_pad(bin2hex($label), 64, '0', STR_PAD_RIGHT);

        $log = [
            'topics'          => [self::topicFor($registrar, 'Registered'), self::addrTopic($publisher), $node],
            'data'            => '0x' . $data,
            'blockNumber'     => '0x1', 'transactionHash' => '0x' . str_repeat('3', 64),
            'logIndex'        => '0x0', 'address' => self::REGISTRAR,
        ];
        $decoded = $this->decoder->decode($log);
        self::assertSame('PublisherRegistrar', $decoded['contract']);
        self::assertSame('Registered', $decoded['event']);
        self::assertSame($publisher, $decoded['args']['publisher']);
        self::assertSame($node, $decoded['args']['node']);
        self::assertSame('genesis', $decoded['args']['label']);
        self::assertSame('2000000', $decoded['args']['bond']);
    }

    /** TreasuryWithdrawn has an identical signature on Registry and ChallengeManager. */
    public function testIdenticalSignatureCollisionResolvedByAddress(): void
    {
        $registry = $this->abi->contractAt(self::REGISTRY);
        $challenge = $this->abi->contractAt(self::CHALLENGE);
        self::assertSame(self::topicFor($registry, 'TreasuryWithdrawn'), self::topicFor($challenge, 'TreasuryWithdrawn'));

        $to = '0xb30af804fd19565e6bcbfdced944fdf654e585d9';
        $mk = fn (string $addr) => [
            'topics'          => [self::topicFor($registry, 'TreasuryWithdrawn'), self::addrTopic($to)],
            'data'            => '0x' . self::word(42),
            'blockNumber'     => '0x1', 'transactionHash' => '0x' . str_repeat('4', 64),
            'logIndex'        => '0x0', 'address' => $addr,
        ];
        self::assertSame('Registry', $this->decoder->decode($mk(self::REGISTRY))['contract']);
        self::assertSame('ChallengeManager', $this->decoder->decode($mk(self::CHALLENGE))['contract']);
    }

    public function testDecodeWordHandlesLargeUint256AsString(): void
    {
        $hugeHex = str_repeat('f', 64);
        $result = EventDecoder::decodeWord('uint256', $hugeHex);
        self::assertIsString($result);
        self::assertSame('115792089237316195423570985008687907853269984665640564039457584007913129639935', $result);
    }
}
