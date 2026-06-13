<?php

declare(strict_types=1);

namespace App\Models\Indexer\Handlers;

use Zephyrus\Data\Database;

/**
 * ImmunityL2Registry ingestion (Phase 2): the publisher ENS subname registry.
 *
 *   SubnodeCreated(node, parentNode, string label, address owner)
 *       → bind the owner publisher to `{label}.immunity.eth` (node + ens)
 *   TextChanged(node, string key, string value)
 *       → mirror `immunity.reputation` onto the publisher's score (display)
 *
 * The explorer reads subnames straight from this registry mirror (CCIP
 * resolution in third-party apps is parked — see DEPLOYED-base-sepolia.md).
 */
class EnsIngestHandler
{
    private const PARENT = 'immunity.eth';

    public function __construct(private readonly Database $db)
    {
    }

    /** @param array<string,mixed> $decoded L2Registry.SubnodeCreated */
    public function handleSubnodeCreated(array $decoded): bool
    {
        $a = $decoded['args'];
        $owner = strtolower(self::stripHex((string) ($a['owner'] ?? '')));
        $node = strtolower(self::stripHex((string) ($a['node'] ?? '')));
        $label = (string) ($a['label'] ?? '');
        if (self::isZero($owner)) {
            return false;
        }
        $ens = $label !== '' ? $label . '.' . self::PARENT : null;

        $this->db->query(
            "INSERT INTO antibody.publisher (address, ens, ens_node, first_seen_at, last_active_at)
             VALUES (?, ?, ?, now(), now())
             ON CONFLICT (address) DO UPDATE SET
                ens      = COALESCE(EXCLUDED.ens, antibody.publisher.ens),
                ens_node = EXCLUDED.ens_node",
            ['\\x' . $owner, $ens, '\\x' . $node]
        );
        return true;
    }

    /** @param array<string,mixed> $decoded L2Registry.TextChanged */
    public function handleTextChanged(array $decoded): bool
    {
        $a = $decoded['args'];
        $node = strtolower(self::stripHex((string) ($a['node'] ?? '')));
        $key = (string) ($a['key'] ?? '');
        $value = (string) ($a['value'] ?? '');

        // Only the reputation mirror maps onto a column; other immunity.* text
        // records are resolved directly from the registry by the explorer.
        if ($key !== 'immunity.reputation' || !is_numeric($value)) {
            return false;
        }
        $row = $this->db->query(
            "UPDATE antibody.publisher
                SET score = ?::numeric(20,6), last_active_at = now()
              WHERE ens_node = ?
              RETURNING address",
            [$value, '\\x' . $node]
        );
        return $row->fetch(\PDO::FETCH_ASSOC) !== false;
    }

    private static function stripHex(string $hex): string
    {
        if (str_starts_with($hex, '0x') || str_starts_with($hex, '0X')) {
            return substr($hex, 2);
        }
        return $hex;
    }

    private static function isZero(string $hex): bool
    {
        return $hex === '' || ltrim($hex, '0') === '';
    }
}
