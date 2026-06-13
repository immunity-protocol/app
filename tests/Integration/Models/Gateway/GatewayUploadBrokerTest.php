<?php

declare(strict_types=1);

namespace Tests\Integration\Models\Gateway;

use App\Models\Gateway\Brokers\GatewayUploadBroker;
use Tests\IntegrationTestCase;

final class GatewayUploadBrokerTest extends IntegrationTestCase
{
    private const string PUB = '0xf39fd6e51aad88f6f4ce6ab8827279cfffb92266';
    private const string PUB2 = '0x0000000000000000000000000000000000000002';

    private function broker(): GatewayUploadBroker
    {
        return new GatewayUploadBroker($this->db);
    }

    public function testRecordThenNonceSeen(): void
    {
        $broker = $this->broker();
        $nonce = bin2hex(random_bytes(16));

        $this->assertFalse($broker->nonceSeen($nonce));
        $id = $broker->record(self::PUB, $nonce, 'QmEvidence', 'QmContext');
        $this->assertIsInt($id);
        $this->assertTrue($broker->nonceSeen($nonce));
    }

    public function testDuplicateNonceReturnsNull(): void
    {
        $broker = $this->broker();
        $nonce = bin2hex(random_bytes(16));

        $this->assertIsInt($broker->record(self::PUB, $nonce, 'QmA', null));
        $this->assertNull($broker->record(self::PUB, $nonce, 'QmB', null));
    }

    public function testCountRecentUploadsIsPerPublisher(): void
    {
        $broker = $this->broker();
        $broker->record(self::PUB, bin2hex(random_bytes(16)), 'QmA', null);
        $broker->record(self::PUB, bin2hex(random_bytes(16)), 'QmB', null);

        $this->assertSame(2, $broker->countRecentUploads(self::PUB, 3600));
        $this->assertSame(0, $broker->countRecentUploads(self::PUB2, 3600));
    }

    public function testCountRecentUploadsRespectsWindow(): void
    {
        $broker = $this->broker();
        $broker->record(self::PUB, bin2hex(random_bytes(16)), 'QmA', null);

        // A zero-second window excludes the just-inserted row.
        $this->assertSame(0, $broker->countRecentUploads(self::PUB, 0));
        $this->assertSame(1, $broker->countRecentUploads(self::PUB, 3600));
    }
}
