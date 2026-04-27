<?php

declare(strict_types=1);

namespace Tests\Unit\Models\Indexer\Handlers;

use App\Models\Core\MirrorChainConfig;
use App\Models\Core\MirrorNetworkRegistry;
use App\Models\Indexer\Handlers\MirrorEnqueueHandler;
use App\Models\Mirror\Brokers\PendingJobsBroker;
use App\Models\Mirror\MirrorEnvelopeBuffer;
use ReflectionClass;
use Tests\TestCase;

final class MirrorEnqueueHandlerTest extends TestCase
{
    private const KECCAK = '0x1111111111111111111111111111111111111111111111111111111111111111';
    private const TARGET = '0xdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef';
    private const SELECTOR = '0xa9059cbb';

    public function testAddressBlockedEnqueuesMirrorAddressPerChain(): void
    {
        $broker  = new RecordingBroker();
        $networks = $this->registryWithChains([11155111, 84532]);
        $buffer   = new MirrorEnvelopeBuffer();
        $buffer->stash(self::KECCAK, $this->fakeEnvelope());

        $handler = new MirrorEnqueueHandler($broker, $networks, $buffer);
        $ok = $handler->handle($this->decoded('AddressBlocked', [
            'keccakId'  => self::KECCAK,
            'target'    => self::TARGET,
            'publisher' => '0xabc' . str_repeat('0', 37),
        ]));

        self::assertTrue($ok);
        self::assertCount(2, $broker->mirrorAddressCalls);
        self::assertSame([11155111, 84532], array_column($broker->mirrorAddressCalls, 'chainId'));
        foreach ($broker->mirrorAddressCalls as $call) {
            self::assertSame(self::TARGET, $call['target']);
            self::assertSame('0xpub', $call['envelope']['publisher']);
        }
    }

    public function testCallPatternBlockedPadsSelectorToBytes32(): void
    {
        $broker  = new RecordingBroker();
        $networks = $this->registryWithChains([11155111]);
        $buffer   = new MirrorEnvelopeBuffer();
        $buffer->stash(self::KECCAK, $this->fakeEnvelope());

        $handler = new MirrorEnqueueHandler($broker, $networks, $buffer);
        $ok = $handler->handle($this->decoded('CallPatternBlocked', [
            'keccakId'  => self::KECCAK,
            'selector'  => self::SELECTOR,
            'publisher' => '0xpub',
        ]));

        self::assertTrue($ok);
        self::assertCount(1, $broker->mirrorCalls);
        $env = $broker->mirrorCalls[0]['envelope'];
        self::assertSame(
            '0xa9059cbb' . str_repeat('0', 56),
            $env['auxiliary_key'],
            'bytes4 selector must be right-padded to bytes32'
        );
    }

    public function testSemanticPatternUsesZeroAuxiliaryKey(): void
    {
        $broker  = new RecordingBroker();
        $networks = $this->registryWithChains([11155111]);
        $buffer   = new MirrorEnvelopeBuffer();
        $buffer->stash(self::KECCAK, $this->fakeEnvelope());

        $handler = new MirrorEnqueueHandler($broker, $networks, $buffer);
        $handler->handle($this->decoded('SemanticPatternAdded', [
            'keccakId' => self::KECCAK,
            'flavor'   => 1,
            'publisher' => '0xpub',
        ]));

        self::assertCount(1, $broker->mirrorCalls);
        self::assertSame('0x' . str_repeat('0', 64), $broker->mirrorCalls[0]['envelope']['auxiliary_key']);
    }

    public function testEmptyBufferIsSilent(): void
    {
        $broker  = new RecordingBroker();
        $networks = $this->registryWithChains([11155111]);
        $buffer   = new MirrorEnvelopeBuffer();
        // intentionally do not stash

        $handler = new MirrorEnqueueHandler($broker, $networks, $buffer);
        $ok = $handler->handle($this->decoded('AddressBlocked', [
            'keccakId' => self::KECCAK,
            'target'   => self::TARGET,
            'publisher' => '0xpub',
        ]));

        self::assertFalse($ok);
        self::assertSame([], $broker->mirrorAddressCalls);
        self::assertSame([], $broker->mirrorCalls);
    }

    public function testBufferDrainedAfterEnqueue(): void
    {
        $broker  = new RecordingBroker();
        $networks = $this->registryWithChains([11155111]);
        $buffer   = new MirrorEnvelopeBuffer();
        $buffer->stash(self::KECCAK, $this->fakeEnvelope());

        $handler = new MirrorEnqueueHandler($broker, $networks, $buffer);
        $handler->handle($this->decoded('AddressBlocked', [
            'keccakId' => self::KECCAK,
            'target'   => self::TARGET,
            'publisher' => '0xpub',
        ]));

        self::assertSame(0, $buffer->size());
    }

    /**
     * Build a registry with the given chain ids without touching disk.
     * Uses reflection to bypass the file loader.
     *
     * @param int[] $chainIds
     */
    private function registryWithChains(array $chainIds): MirrorNetworkRegistry
    {
        $chains = [];
        foreach ($chainIds as $id) {
            $chains[$id] = new MirrorChainConfig(
                chainId: $id,
                name: "chain-$id",
                rpcUrl: 'https://rpc.test',
                mirrorAddress: '0x' . str_pad((string) $id, 40, '0', STR_PAD_LEFT),
                deployBlock: 1000,
                relayerPrivateKeyEnv: "RELAYER_PRIVATE_KEY_$id",
            );
        }
        $ref = new ReflectionClass(MirrorNetworkRegistry::class);
        $instance = $ref->newInstanceWithoutConstructor();
        $prop = $ref->getProperty('chains');
        $prop->setAccessible(true);
        $prop->setValue($instance, $chains);
        return $instance;
    }

    /** @return array<string, mixed> */
    private function fakeEnvelope(): array
    {
        return [
            'publisher' => '0xpub',
            'evidenceCid' => '0x' . str_repeat('a', 64),
            'attestation' => '0x' . str_repeat('b', 64),
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @return array{event:string,args:array<string,mixed>,blockNumber:int,txHash:string,logIndex:int,address:string}
     */
    private function decoded(string $event, array $args): array
    {
        return [
            'event'       => $event,
            'args'        => $args,
            'blockNumber' => 1,
            'txHash'      => '0x' . str_repeat('c', 64),
            'logIndex'    => 0,
            'address'     => '0x' . str_repeat('d', 40),
        ];
    }
}

/**
 * Minimal recording broker for the handler test. Only the enqueue methods are
 * exercised; the handler doesn't call anything else.
 */
final class RecordingBroker extends PendingJobsBroker
{
    /** @var array<int, array{keccak:string,chainId:int,envelope:array<string,mixed>}> */
    public array $mirrorCalls = [];

    /** @var array<int, array{keccak:string,chainId:int,envelope:array<string,mixed>,target:string}> */
    public array $mirrorAddressCalls = [];

    /** @var array<int, array{keccak:string,chainId:int}> */
    public array $unmirrorCalls = [];

    public function __construct()
    {
        // Skip the parent constructor (which would require a real DB).
    }

    public function enqueueMirror(string $keccakIdHex, int $chainId, array $envelope): void
    {
        $this->mirrorCalls[] = ['keccak' => $keccakIdHex, 'chainId' => $chainId, 'envelope' => $envelope];
    }

    public function enqueueMirrorAddress(string $keccakIdHex, int $chainId, array $envelope, string $targetAddressHex): void
    {
        $this->mirrorAddressCalls[] = [
            'keccak' => $keccakIdHex,
            'chainId' => $chainId,
            'envelope' => $envelope,
            'target' => $targetAddressHex,
        ];
    }

    public function enqueueUnmirror(string $keccakIdHex, int $chainId): void
    {
        $this->unmirrorCalls[] = ['keccak' => $keccakIdHex, 'chainId' => $chainId];
    }
}
