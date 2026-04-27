<?php

declare(strict_types=1);

namespace App\Models\Indexer\Workers;

use Zephyrus\Data\Database;

/**
 * Marks active antibodies whose expires_at is in the past as 'expired'.
 *
 * The Registry contract emits no event for expiry; consumers compute it at
 * read time. We reflect the reality on the indexer side so the explorer's
 * status filter and the dashboard counters stay coherent.
 */
class ExpirySweep
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return int rows updated
     */
    public function run(): int
    {
        $stmt = $this->db->query(
            "UPDATE antibody.entry
                SET status     = 'expired'::antibody.entry_status,
                    updated_at = now()
              WHERE expires_at IS NOT NULL
                AND expires_at < now()
                AND status = 'active'::antibody.entry_status"
        );
        return $stmt->rowCount();
    }
}
