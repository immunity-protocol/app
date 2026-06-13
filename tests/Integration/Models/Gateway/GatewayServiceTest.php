<?php

declare(strict_types=1);

namespace Tests\Integration\Models\Gateway;

use App\Models\Gateway\Brokers\GatewayUploadBroker;
use App\Models\Gateway\GatewayException;
use App\Models\Gateway\GatewayRequestVerifier;
use App\Models\Gateway\GatewayService;
use App\Models\Gateway\RegistrationGate;
use App\Models\Gateway\Storage\EvidenceStore;
use Tests\Fixtures\Gateway\GatewayFixture;
use Tests\IntegrationTestCase;

/**
 * Full /evidence pipeline against the real upload ledger (txn-isolated), with a
 * stub registration gate and an in-memory content-addressed store (no network).
 */
final class GatewayServiceTest extends IntegrationTestCase
{
    private const int NOW_MS = 1749772800000;

    private function gate(bool $registered): RegistrationGate
    {
        return new class ($registered) implements RegistrationGate {
            public function __construct(private bool $registered) {}
            public function isRegistered(string $address): bool { return $this->registered; }
        };
    }

    private function store(): EvidenceStore
    {
        return new class implements EvidenceStore {
            public array $stored = [];
            public function store(string $content): string
            {
                $cid = 'Qm' . substr(hash('sha256', $content), 0, 44);
                $this->stored[$cid] = $content;
                return $cid;
            }
        };
    }

    private function service(RegistrationGate $gate, EvidenceStore $store, int $quota = 60): GatewayService
    {
        return new GatewayService(
            new GatewayRequestVerifier(),
            $gate,
            $store,
            new GatewayUploadBroker($this->db),
            $quota,
        );
    }

    public function testHappyPathStoresBothArtifactsAndRecordsRow(): void
    {
        $store = $this->store();
        $service = $this->service($this->gate(true), $store);
        $json = GatewayFixture::requestJson('withContext', ['timestamp' => self::NOW_MS]);

        $res = $service->process($json, self::NOW_MS);

        $this->assertArrayHasKey('evidenceCid', $res);
        $this->assertArrayHasKey('contextCid', $res);
        $this->assertCount(2, $store->stored);
        $broker = new GatewayUploadBroker($this->db);
        $this->assertSame(1, $broker->countRecentUploads('0xf39fd6e51aad88f6f4ce6ab8827279cfffb92266', 3600));
    }

    public function testHappyPathNoContextReturnsOnlyEvidenceCid(): void
    {
        $store = $this->store();
        $service = $this->service($this->gate(true), $store);
        $json = GatewayFixture::requestJson('noContext', ['timestamp' => self::NOW_MS]);

        $res = $service->process($json, self::NOW_MS);

        $this->assertArrayHasKey('evidenceCid', $res);
        $this->assertArrayNotHasKey('contextCid', $res);
        $this->assertCount(1, $store->stored);
    }

    public function testUnregisteredPublisherRejectedWith403(): void
    {
        $service = $this->service($this->gate(false), $this->store());
        $json = GatewayFixture::requestJson('withContext', ['timestamp' => self::NOW_MS]);

        $this->expectRejection(403, fn () => $service->process($json, self::NOW_MS));
    }

    public function testReplayedNonceRejectedWith409(): void
    {
        $service = $this->service($this->gate(true), $this->store());
        $nonce = bin2hex(random_bytes(16));
        $json = GatewayFixture::requestJson('withContext', ['timestamp' => self::NOW_MS, 'nonce' => $nonce]);

        $service->process($json, self::NOW_MS);
        $this->expectRejection(409, fn () => $service->process($json, self::NOW_MS));
    }

    public function testOverQuotaRejectedWith429(): void
    {
        $service = $this->service($this->gate(true), $this->store(), quota: 1);

        $service->process(GatewayFixture::requestJson('withContext', ['timestamp' => self::NOW_MS]), self::NOW_MS);
        $this->expectRejection(
            429,
            fn () => $service->process(GatewayFixture::requestJson('withContext', ['timestamp' => self::NOW_MS]), self::NOW_MS),
        );
    }

    public function testBadSignatureRejectedWith401(): void
    {
        $service = $this->service($this->gate(true), $this->store());
        $json = GatewayFixture::requestJson('withContext', [
            'timestamp' => self::NOW_MS,
            'signature' => '0x' . str_repeat('00', 65),
        ]);

        $this->expectRejection(401, fn () => $service->process($json, self::NOW_MS));
    }

    private function expectRejection(int $status, callable $fn): void
    {
        try {
            $fn();
            $this->fail("expected GatewayException $status");
        } catch (GatewayException $e) {
            $this->assertSame($status, $e->httpStatus, $e->getMessage());
        }
    }
}
