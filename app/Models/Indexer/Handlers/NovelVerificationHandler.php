<?php

declare(strict_types=1);

namespace App\Models\Indexer\Handlers;

use App\Models\Event\Brokers\ContractEventBroker;

/**
 * NovelVerification (per-check CRE verification) ingestion.
 *
 *   VerificationRequested(bytes32 indexed checkId, address indexed requester,
 *                         bytes32 evidenceCid, bytes32 contextHash)
 *   Verified(bytes32 indexed checkId, uint8 verdict, uint16 confidence, uint8 severity)
 *
 * Both land in event.contract_event (the generic feed table) as
 * "NovelVerification.VerificationRequested" / "NovelVerification.Verified",
 * idempotent on (tx_hash, log_index). The Verified payload carries the decoded
 * verdict label (BENIGN/SUSPICIOUS/MALICIOUS) plus confidence/severity so the
 * dashboard event feed can render it without re-deriving the enum.
 */
class NovelVerificationHandler
{
    /** verdict enum -> label */
    private const VERDICTS = [0 => 'BENIGN', 1 => 'SUSPICIOUS', 2 => 'MALICIOUS'];

    public function __construct(private readonly ContractEventBroker $broker)
    {
    }

    /** @param array<string,mixed> $decoded NovelVerification.VerificationRequested */
    public function handleRequested(array $decoded): bool
    {
        $a = $decoded['args'];
        $payload = [
            'checkId'     => (string) ($a['checkId'] ?? ''),
            'requester'   => (string) ($a['requester'] ?? ''),
            'evidenceCid' => (string) ($a['evidenceCid'] ?? ''),
            'contextHash' => (string) ($a['contextHash'] ?? ''),
        ];
        return $this->store($decoded, 'VerificationRequested', $payload);
    }

    /** @param array<string,mixed> $decoded NovelVerification.Verified */
    public function handleVerified(array $decoded): bool
    {
        $a = $decoded['args'];
        $verdict = (int) ($a['verdict'] ?? 0);
        $payload = [
            'checkId'      => (string) ($a['checkId'] ?? ''),
            'verdict'      => $verdict,
            'verdictLabel' => self::VERDICTS[$verdict] ?? ('UNKNOWN(' . $verdict . ')'),
            'confidence'   => (int) ($a['confidence'] ?? 0),
            'severity'     => (int) ($a['severity'] ?? 0),
        ];
        return $this->store($decoded, 'Verified', $payload);
    }

    /**
     * @param array<string,mixed> $decoded
     * @param array<string,mixed> $payload
     */
    private function store(array $decoded, string $event, array $payload): bool
    {
        $name = ($decoded['contract'] ?? 'NovelVerification') . '.' . $event;
        $txHashHex = strtolower(self::stripHex((string) ($decoded['txHash'] ?? '')));
        $id = $this->broker->insert(
            $name,
            $payload,
            (int) ($decoded['blockNumber'] ?? 0),
            '\\x' . $txHashHex,
            (int) ($decoded['logIndex'] ?? 0),
            gmdate('Y-m-d\TH:i:s\Z')
        );
        return $id !== null;
    }

    private static function stripHex(string $hex): string
    {
        if (str_starts_with($hex, '0x') || str_starts_with($hex, '0X')) {
            return substr($hex, 2);
        }
        return $hex;
    }
}
