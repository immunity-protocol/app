<?php

declare(strict_types=1);

namespace Tests\Integration\Models\Indexer\Handlers;

use App\Models\Event\Brokers\ContractEventBroker;
use App\Models\Indexer\Handlers\NovelVerificationHandler;
use Tests\IntegrationTestCase;

/**
 * Per-check CRE verification ingestion: VerificationRequested + Verified land in
 * event.contract_event with their decoded payload (verdict enum -> label).
 */
final class NovelVerificationHandlerTest extends IntegrationTestCase
{
    private function event(string $event, array $args, int $logIndex = 0, string $tx = '9'): array
    {
        return [
            'contract' => 'NovelVerification', 'event' => $event, 'args' => $args,
            'blockNumber' => 42799000, 'txHash' => '0x' . str_repeat($tx, 64),
            'logIndex' => $logIndex, 'address' => '0x' . str_repeat('e1', 20),
        ];
    }

    private function row(string $name): ?object
    {
        $r = $this->db->query(
            'SELECT event_name, payload FROM event.contract_event WHERE event_name = ? ORDER BY id DESC LIMIT 1',
            [$name]
        )->fetch(\PDO::FETCH_OBJ);
        return $r === false ? null : $r;
    }

    public function testVerifiedMapsVerdictEnumToLabel(): void
    {
        $h = new NovelVerificationHandler(new ContractEventBroker($this->db));

        self::assertTrue($h->handleVerified($this->event('Verified', [
            'checkId' => '0x' . str_repeat('bb', 32), 'verdict' => 2, 'confidence' => 98, 'severity' => 95,
        ])));

        $row = $this->row('NovelVerification.Verified');
        self::assertNotNull($row);
        $payload = json_decode($row->payload, true);
        self::assertSame('MALICIOUS', $payload['verdictLabel']);
        self::assertSame(98, $payload['confidence']);
        self::assertSame(95, $payload['severity']);
    }

    public function testRequestedIsRecordedAndIdempotent(): void
    {
        $h = new NovelVerificationHandler(new ContractEventBroker($this->db));
        $event = $this->event('VerificationRequested', [
            'checkId' => '0x' . str_repeat('cc', 32), 'requester' => '0x' . str_repeat('11', 20),
            'evidenceCid' => '0x' . str_repeat('dd', 32), 'contextHash' => '0x' . str_repeat('ee', 32),
        ], 1);

        self::assertTrue($h->handleRequested($event));
        // Same (tx_hash, log_index) -> no duplicate row.
        self::assertFalse($h->handleRequested($event));
        self::assertNotNull($this->row('NovelVerification.VerificationRequested'));
    }
}
