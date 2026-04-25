<?php

declare(strict_types=1);

namespace App\Models\Mock;

/**
 * Generates a deterministic roster of mock publisher identities (address + ens).
 *
 * Counts (antibodies_published, successful_blocks, ...) are populated later
 * by the orchestrator via PublisherBroker::recomputeAggregates() once entries
 * and block events have been written.
 */
final class PublisherFactory
{
    private const ENS_NAMES = [
        'huntress.eth', 'scout.eth', 'sentinel.eth', 'wolf.eth', 'vigil.eth',
        'oracle.eth', 'phantom.eth', 'watcher.eth', 'shield.eth', 'ranger.eth',
        'warden.eth', 'marshal.eth', 'sleuth.eth', 'prowler.eth', 'tracker.eth',
        'hunter.eth', 'defender.eth', 'nemesis.eth', 'alpha.eth', 'ghost.eth',
        'claw.eth', 'fang.eth', 'talon.eth', 'hawkeye.eth', 'skywatch.eth',
        'nightshade.eth', 'crucible.eth', 'vaultkeeper.eth', 'hexkey.eth',
        'raven.eth', 'wraith.eth', 'lockbreaker.eth', 'helix.eth', 'sigma.eth',
        'viper.eth', 'falcon.eth', 'panther.eth', 'bobcat.eth', 'wolverine.eth',
        'badger.eth', 'jackal.eth', 'lynx.eth', 'kraken.eth', 'sphinx.eth',
        'maelstrom.eth', 'argus.eth', 'cerberus.eth', 'minotaur.eth',
    ];

    /** @var list<array{address: string, ens: ?string}> */
    private array $publishers = [];

    public function __construct(int $count = 80)
    {
        $this->generate($count);
    }

    /**
     * @return list<array{address: string, ens: ?string}>
     */
    public function all(): array
    {
        return $this->publishers;
    }

    /**
     * @return array{address: string, ens: ?string}
     */
    public function pickRandom(): array
    {
        return Seeds::pick($this->publishers);
    }

    private function generate(int $count): void
    {
        // 60% of publishers get ENS names, capped at the available pool.
        $ensCount = min(count(self::ENS_NAMES), (int) round($count * 0.60));
        $ensPool = array_slice(self::ENS_NAMES, 0, $ensCount);

        for ($i = 0; $i < $count; $i++) {
            $address = $this->randomAddress($i);
            $ens = $i < $ensCount ? $ensPool[$i] : null;
            $this->publishers[] = [
                'address' => $address,
                'ens'     => $ens,
            ];
        }
    }

    private function randomAddress(int $index): string
    {
        // Deterministic 20-byte address derived from index + a couple of mt_rand draws.
        $entropy = pack('N', $index) . pack('N', Seeds::int(0, PHP_INT_MAX)) . pack('N', Seeds::int(0, PHP_INT_MAX));
        return substr(hash('sha256', $entropy, true), 0, 20);
    }
}
