<?php

declare(strict_types=1);

namespace Tests\Fixtures\Mock;

/**
 * Generates mock mirror rows for ADDRESS antibodies.
 *
 *   - 60% of ADDRESS entries get mirrored to Sepolia.
 *   - Of those: 30% also Base, 20% also Arbitrum, 15% also Optimism.
 *   - mirrored_at lags entry.created_at by 5-30s (realistic relay latency).
 */
final class MirrorFactory
{
    private const CHAINS = [
        'sepolia'  => ['id' => 11155111, 'extra_chance' => 1.00],
        'base'     => ['id' => 8453,     'extra_chance' => 0.30],
        'arbitrum' => ['id' => 42161,    'extra_chance' => 0.20],
        'optimism' => ['id' => 10,       'extra_chance' => 0.15],
    ];

    /**
     * @param list<array{id: int, type: string, created_at: string}> $entries
     * @return list<array<string, mixed>>
     */
    public function generate(array $entries): array
    {
        $rows = [];
        foreach ($entries as $entry) {
            if ($entry['type'] !== 'address') {
                continue;
            }
            if (!Seeds::chance(0.60)) {
                continue;
            }
            // Always sepolia; then a roll for each additional chain.
            foreach (self::CHAINS as $chainName => $chain) {
                if ($chainName !== 'sepolia' && !Seeds::chance($chain['extra_chance'])) {
                    continue;
                }
                $rows[] = $this->mirrorRow($entry, $chainName, $chain['id']);
            }
        }
        return $rows;
    }

    /**
     * @param array{id: int, created_at: string} $entry
     * @return array<string, mixed>
     */
    private function mirrorRow(array $entry, string $chainName, int $chainId): array
    {
        $lagSeconds = Seeds::int(5, 30);
        $mirroredAt = (new \DateTimeImmutable($entry['created_at']))
            ->add(new \DateInterval('PT' . $lagSeconds . 'S'))
            ->format('Y-m-d H:i:sP');
        return [
            'entry_id'        => $entry['id'],
            'chain_id'        => $chainId,
            'chain_name'      => $chainName,
            'mirror_tx_hash'  => '\\x' . bin2hex(random_bytes(32)),
            'mirrored_at'     => $mirroredAt,
            'status'          => 'active',
            'relayer_address' => '\\x' . bin2hex(random_bytes(20)),
        ];
    }
}
