<?php

declare(strict_types=1);

namespace App\Models\Indexer\Workers;

use Ens\EnsService;
use Throwable;
use Zephyrus\Data\Database;

/**
 * Refreshes antibody.publisher.ens by reverse-resolving each address against
 * Ethereum mainnet. Caching is in Postgres: we re-resolve at most every 7
 * days (or 24h for previously-empty resolutions). Per tick we touch a small
 * batch so a flaky or slow mainnet RPC does not stall the indexer.
 *
 * Also denormalizes the resolved name into antibody.entry.publisher_ens so
 * the explorer's UI can avoid a join on hot lists.
 */
class EnsResolutionWorker
{
    public function __construct(
        private readonly Database $db,
        private readonly EnsService $ens,
    ) {
    }

    /**
     * @return array{checked:int,resolved:int,empty:int,failed:int}
     */
    public function tick(int $batchSize = 5): array
    {
        $rows = $this->db->query(
            "SELECT encode(address, 'hex') AS addr_hex
               FROM antibody.publisher
              WHERE last_ens_resolved_at IS NULL
                 OR (ens IS NOT NULL AND last_ens_resolved_at < now() - interval '7 days')
                 OR (ens IS NULL AND last_ens_resolved_at < now() - interval '24 hours')
              ORDER BY last_ens_resolved_at NULLS FIRST, last_active_at DESC
              LIMIT ?",
            [$batchSize]
        )->fetchAll(\PDO::FETCH_ASSOC);

        $stats = ['checked' => 0, 'resolved' => 0, 'empty' => 0, 'failed' => 0];

        foreach ($rows as $r) {
            $stats['checked']++;
            $addrHex = (string) $r['addr_hex'];
            $address = '0x' . $addrHex;
            $ens = null;
            try {
                $ens = $this->ens->resolveEnsName($address);
            } catch (Throwable $e) {
                $stats['failed']++;
                fwrite(STDERR, "[EnsWorker] resolve failed for $address: " . $e->getMessage() . PHP_EOL);
            }

            if ($ens !== null) {
                $stats['resolved']++;
            } else {
                $stats['empty']++;
            }

            $this->db->query(
                "UPDATE antibody.publisher
                    SET ens = ?,
                        last_ens_resolved_at = now()
                  WHERE address = ?",
                [$ens, '\\x' . $addrHex]
            );

            // Denormalize into entries with this publisher.
            $this->db->query(
                "UPDATE antibody.entry
                    SET publisher_ens = ?,
                        updated_at    = now()
                  WHERE publisher = ?",
                [$ens, '\\x' . $addrHex]
            );
        }

        return $stats;
    }
}
