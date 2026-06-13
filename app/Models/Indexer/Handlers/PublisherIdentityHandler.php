<?php

declare(strict_types=1);

namespace App\Models\Indexer\Handlers;

use Zephyrus\Data\Database;

/**
 * PublisherRegistrar identity events.
 *
 *   Registered(publisher, node, string label, uint256 bond)
 *   Deregistered(publisher, uint256 bondReturned)
 *   ReputationSynced(publisher, node, uint256 score, uint64 strikes)
 *
 * Maintains the publisher's ENS subname identity (node + `{label}.immunity.eth`)
 * and registration bond on antibody.publisher. All field writes are SET (no
 * accumulators), so they are naturally idempotent on replay.
 */
class PublisherIdentityHandler
{
    /** Parent ENS name for publisher subnames. */
    private const PARENT = 'immunity.eth';

    public function __construct(private readonly Database $db)
    {
    }

    /** @param array<string,mixed> $decoded */
    public function handleRegistered(array $decoded): bool
    {
        $a = $decoded['args'];
        $publisher = strtolower(self::stripHex((string) $a['publisher']));
        $nodeHex = strtolower(self::stripHex((string) $a['node']));
        $label = (string) ($a['label'] ?? '');
        $bond = self::weiToUsdc((string) $a['bond']);
        $ens = $label !== '' ? $label . '.' . self::PARENT : null;

        $this->db->query(
            "INSERT INTO antibody.publisher
                (address, ens, ens_node, registration_bond, registered_at, deregistered, first_seen_at, last_active_at)
             VALUES (?, ?, ?, ?::numeric(20,6), now(), false, now(), now())
             ON CONFLICT (address) DO UPDATE SET
                ens               = COALESCE(EXCLUDED.ens, antibody.publisher.ens),
                ens_node          = EXCLUDED.ens_node,
                registration_bond = EXCLUDED.registration_bond,
                registered_at     = COALESCE(antibody.publisher.registered_at, EXCLUDED.registered_at),
                deregistered      = false,
                last_active_at    = now()",
            ['\\x' . $publisher, $ens, '\\x' . $nodeHex, $bond]
        );
        return true;
    }

    /** @param array<string,mixed> $decoded */
    public function handleDeregistered(array $decoded): bool
    {
        $publisher = strtolower(self::stripHex((string) $decoded['args']['publisher']));
        $row = $this->db->query(
            "UPDATE antibody.publisher
                SET deregistered = true, last_active_at = now()
              WHERE address = ?
              RETURNING address",
            ['\\x' . $publisher]
        );
        return $row->fetch(\PDO::FETCH_ASSOC) !== false;
    }

    /** @param array<string,mixed> $decoded */
    public function handleReputationSynced(array $decoded): bool
    {
        $a = $decoded['args'];
        $publisher = strtolower(self::stripHex((string) $a['publisher']));
        $score = (string) $a['score'];
        $this->db->query(
            "INSERT INTO antibody.publisher (address, score, first_seen_at, last_active_at)
             VALUES (?, ?::numeric(20,6), now(), now())
             ON CONFLICT (address) DO UPDATE SET
                score          = EXCLUDED.score,
                last_active_at = now()",
            ['\\x' . $publisher, $score]
        );
        return true;
    }

    private static function stripHex(string $hex): string
    {
        if (str_starts_with($hex, '0x') || str_starts_with($hex, '0X')) {
            return substr($hex, 2);
        }
        return $hex;
    }

    private static function weiToUsdc(string $value): string
    {
        if (function_exists('bcdiv')) {
            return bcdiv($value, '1000000', 6);
        }
        return number_format(((float) $value) / 1_000_000, 6, '.', '');
    }
}
