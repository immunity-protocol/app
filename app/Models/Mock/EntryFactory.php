<?php

declare(strict_types=1);

namespace App\Models\Mock;

/**
 * Generates mock antibody.entry rows with realistic distributions:
 *   - 60% address / 12% call_pattern / 8% bytecode / 8% graph / 12% semantic
 *   - 92% active / 4% challenged / 1% slashed / 3% expired
 *   - Spread over the last 90 days, with ~30 antibodies in the last 24h
 *   - ~25% carry a seed_source (genesis-seeded antibodies)
 *   - Confidence concentrated 70-95, occasional 60 (escalate threshold) and >95
 *
 * Returns insert-ready payloads (bytea fields encoded as Postgres \x hex).
 */
final class EntryFactory
{
    private const TYPE_WEIGHTS = [
        'address'      => 60,
        'call_pattern' => 12,
        'bytecode'     => 8,
        'graph'        => 8,
        'semantic'     => 12,
    ];

    private const STATUS_WEIGHTS = [
        'active'     => 92,
        'challenged' => 4,
        'slashed'    => 1,
        'expired'    => 3,
    ];

    private const SEED_SOURCES = [
        'certik_skynet', 'forta', 'metamask_warnlist',
        'chainalysis_sanctions', 'scamsniffer', 'honeypot_is',
        'tornado_set', 'etherscan_labels',
    ];

    private const SEMANTIC_FLAVORS = ['counterparty', 'pattern', 'injection'];

    public function __construct(
        private readonly PublisherFactory $publishers,
        private readonly int $total = 350,
        private readonly int $recentBurst = 30,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function generate(): array
    {
        $rows = [];
        for ($i = 1; $i <= $this->total; $i++) {
            $isRecent = $i <= $this->recentBurst;
            $rows[] = $this->generateOne($i, $isRecent);
        }
        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function generateOne(int $index, bool $isRecent): array
    {
        $immId = sprintf('IMM-2026-%04d', $index);
        $type = Seeds::weighted(self::TYPE_WEIGHTS);
        $verdict = $type === 'graph' && Seeds::chance(0.7) ? 'suspicious' : 'malicious';
        $status = Seeds::weighted(self::STATUS_WEIGHTS);
        $confidence = $this->generateConfidence();
        $severity = (int) min(99, max(20, round(Seeds::logNormal(60, 0.4))));
        $publisher = $this->publishers->pickRandom();
        $createdAt = $this->randomCreatedAt($isRecent);
        $stakeLockUntil = $this->plusHours($createdAt, 72);
        $expiresAt = Seeds::chance(0.7)
            ? $this->plusHours($createdAt, Seeds::int(7 * 24, 365 * 24))
            : null;
        $keccakHex = bin2hex(random_bytes(32));
        $contextHashHex = bin2hex(random_bytes(32));
        $evidenceCidHex = bin2hex(random_bytes(32));
        $attestationHex = bin2hex(random_bytes(32));
        $row = [
            'keccak_id'         => '\\x' . $keccakHex,
            'imm_id'            => $immId,
            'type'              => $type,
            'verdict'           => $verdict,
            'confidence'        => $confidence,
            'severity'          => $severity,
            'status'            => $status,
            'primary_matcher'   => $this->primaryMatcher($type),
            'context_hash'      => '\\x' . $contextHashHex,
            'evidence_cid'      => '\\x' . $evidenceCidHex,
            'stake_lock_until'  => $stakeLockUntil,
            'expires_at'        => $expiresAt,
            'publisher'         => '\\x' . bin2hex($publisher['address']),
            'publisher_ens'     => $publisher['ens'],
            'stake_amount'      => '1.000000',
            'attestation'       => '\\x' . $attestationHex,
            'redacted_reasoning' => $this->redactedReasoning($type, $verdict),
            'created_at'        => $createdAt,
            'updated_at'        => $createdAt,
        ];
        if ($type === 'semantic') {
            $row['flavor'] = Seeds::pick(self::SEMANTIC_FLAVORS);
        }
        if (Seeds::chance(0.25)) {
            $row['seed_source'] = Seeds::pick(self::SEED_SOURCES);
        }
        if ($type === 'semantic') {
            $row['embedding_hash'] = '\\x' . bin2hex(random_bytes(32));
            $row['embedding_cid'] = '\\x' . bin2hex(random_bytes(32));
        }
        return $row;
    }

    private function generateConfidence(): int
    {
        if (Seeds::chance(0.04)) {
            return 60;
        }
        if (Seeds::chance(0.05)) {
            return Seeds::int(96, 99);
        }
        return (int) round(Seeds::skewed(70.0, 95.0, 84.0));
    }

    private function randomCreatedAt(bool $isRecent): string
    {
        $now = new \DateTimeImmutable('2026-04-25 12:00:00', new \DateTimeZone('UTC'));
        if ($isRecent) {
            $secondsAgo = Seeds::int(60, 24 * 3600);
        } else {
            $secondsAgo = Seeds::int(24 * 3600, 90 * 24 * 3600);
        }
        $created = $now->sub(new \DateInterval('PT' . $secondsAgo . 'S'));
        return $created->format('Y-m-d H:i:sP');
    }

    private function plusHours(string $iso, int $hours): string
    {
        $dt = new \DateTimeImmutable($iso);
        return $dt->add(new \DateInterval('PT' . $hours . 'H'))->format('Y-m-d H:i:sP');
    }

    private function primaryMatcher(string $type): string
    {
        return match ($type) {
            'address'      => json_encode(['address' => '0x' . bin2hex(random_bytes(20))]),
            'call_pattern' => json_encode([
                'selector' => '0x' . bin2hex(random_bytes(4)),
                'args'     => ['MAX_UINT256', '0x' . bin2hex(random_bytes(20))],
            ]),
            'bytecode'     => json_encode(['runtime_hash' => '0x' . bin2hex(random_bytes(32))]),
            'graph'        => json_encode(['root' => '0x' . bin2hex(random_bytes(20)), 'depth' => Seeds::int(2, 6)]),
            'semantic'     => json_encode([
                'embedding_id' => '0x' . bin2hex(random_bytes(16)),
                'tags'         => ['manipulation', 'social'],
            ]),
        };
    }

    private function redactedReasoning(string $type, string $verdict): string
    {
        return match ($type) {
            'address'      => 'On-chain history matches a known scam-cluster heuristic.',
            'call_pattern' => 'Approval to spender with no recoverable provenance and unbounded allowance.',
            'bytecode'     => 'Runtime hash matches a previously-flagged honeypot family.',
            'graph'        => 'Taint topology shows funds routed through a sanctioned mixer within two hops.',
            'semantic'     => 'Counterparty identity overlaps with a corpus of confirmed manipulators.',
        };
    }
}
