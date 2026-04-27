<?php

declare(strict_types=1);

namespace Tests\Fixtures\Mock;

/**
 * Derives a mock event.activity feed from the seeded entries, mirrors and
 * block events. Each input becomes one or more activity rows so the home
 * page activity table has plausible cross-event-type content out of the box.
 */
final class ActivityFactory
{
    /**
     * @param list<array{id: int, imm_id: string, type: string, publisher_ens: ?string, created_at: string}> $entries
     * @param list<array{entry_id: int, chain_name: string, mirrored_at: string}> $mirrors
     * @param list<array{entry_id: int, value_protected_usd: string, agent_id: string, occurred_at: string}> $blockEvents
     * @return list<array<string, mixed>>
     */
    public function generate(array $entries, array $mirrors, array $blockEvents): array
    {
        $rows = [];

        // Most recent published events: one per entry from the recent burst.
        foreach (array_slice($entries, 0, 50) as $entry) {
            $rows[] = [
                'event_type' => 'published',
                'entry_id'   => $entry['id'],
                'payload'    => json_encode([
                    'imm_id' => $entry['imm_id'],
                    'type'   => $entry['type'],
                ]),
                'actor'      => $entry['publisher_ens'] ?? 'publisher',
                'occurred_at' => $entry['created_at'],
            ];
        }

        // Recent mirrors as "mirrored" events.
        foreach (array_slice($mirrors, 0, 50) as $mirror) {
            $rows[] = [
                'event_type' => 'mirrored',
                'entry_id'   => $mirror['entry_id'],
                'payload'    => json_encode(['chain' => $mirror['chain_name']]),
                'actor'      => 'relayer',
                'occurred_at' => $mirror['mirrored_at'],
            ];
        }

        // Recent block events as "protected" events.
        foreach (array_slice($blockEvents, 0, 50) as $block) {
            $rows[] = [
                'event_type' => 'protected',
                'entry_id'   => $block['entry_id'],
                'payload'    => json_encode(['value_usd' => $block['value_protected_usd']]),
                'actor'      => $block['agent_id'],
                'occurred_at' => $block['occurred_at'],
            ];
        }

        // Sort by occurred_at descending so newest land at the top.
        usort($rows, fn ($a, $b) => strcmp($b['occurred_at'], $a['occurred_at']));
        return $rows;
    }
}
