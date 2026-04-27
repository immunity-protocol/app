<?php

declare(strict_types=1);

namespace Tests\Unit\Models\Core;

use App\Models\Core\MirrorNetworkRegistry;
use RuntimeException;
use Tests\TestCase;

final class MirrorNetworkRegistryTest extends TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'mirror-net-') . '.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->tmpFile)) {
            unlink($this->tmpFile);
        }
        putenv('TEST_RPC_URL');
    }

    public function testSkipsChainsWithoutMirrorOrDeployBlock(): void
    {
        file_put_contents($this->tmpFile, json_encode([
            '11155111' => [
                'name' => 'sepolia',
                'rpcUrl' => 'https://sep',
                'mirror' => '0x1be1Ec2F7E2230f9bB1Aa3d5589bB58F8DfD52c7',
                'deployBlock' => 10744251,
                'relayerPrivateKeyEnv' => 'RELAYER_PRIVATE_KEY_SEPOLIA',
            ],
            '84532' => [
                'name' => 'base-sepolia',
                'rpcUrl' => 'https://base',
                'mirror' => null,
                'deployBlock' => null,
                'relayerPrivateKeyEnv' => 'RELAYER_PRIVATE_KEY_BASE_SEPOLIA',
            ],
        ]));

        $registry = MirrorNetworkRegistry::fromFile($this->tmpFile);

        $all = $registry->all();
        self::assertCount(1, $all);
        self::assertNotNull($registry->get(11155111));
        self::assertNull($registry->get(84532));
    }

    public function testExpandsEnvPlaceholdersInRpcUrl(): void
    {
        putenv('TEST_RPC_URL=https://my-real-rpc.example/abc');
        file_put_contents($this->tmpFile, json_encode([
            '11155111' => [
                'name' => 'sepolia',
                'rpcUrl' => '${TEST_RPC_URL}',
                'mirror' => '0x1be1Ec2F7E2230f9bB1Aa3d5589bB58F8DfD52c7',
                'deployBlock' => 10744251,
                'relayerPrivateKeyEnv' => 'RELAYER_PRIVATE_KEY_SEPOLIA',
            ],
        ]));

        $chain = MirrorNetworkRegistry::fromFile($this->tmpFile)->get(11155111);
        self::assertNotNull($chain);
        self::assertSame('https://my-real-rpc.example/abc', $chain->rpcUrl);
        self::assertSame('0x1be1ec2f7e2230f9bb1aa3d5589bb58f8dfd52c7', $chain->mirrorAddress);
        self::assertSame(10744251, $chain->deployBlock);
    }

    public function testRelayerPrivateKeyReadsFromEnv(): void
    {
        file_put_contents($this->tmpFile, json_encode([
            '11155111' => [
                'name' => 'sepolia',
                'rpcUrl' => 'https://x',
                'mirror' => '0x1be1Ec2F7E2230f9bB1Aa3d5589bB58F8DfD52c7',
                'deployBlock' => 10744251,
                'relayerPrivateKeyEnv' => 'TEST_RELAYER_KEY_SEPOLIA',
            ],
        ]));
        $chain = MirrorNetworkRegistry::fromFile($this->tmpFile)->get(11155111);

        putenv('TEST_RELAYER_KEY_SEPOLIA');
        self::assertNull($chain->relayerPrivateKey());

        putenv('TEST_RELAYER_KEY_SEPOLIA=0xabc123');
        self::assertSame('0xabc123', $chain->relayerPrivateKey());
        putenv('TEST_RELAYER_KEY_SEPOLIA');
    }

    public function testMissingFileThrows(): void
    {
        $this->expectException(RuntimeException::class);
        MirrorNetworkRegistry::fromFile('/no/such/path/mirror-network.json');
    }
}
