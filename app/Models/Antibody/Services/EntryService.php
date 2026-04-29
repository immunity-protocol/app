<?php

declare(strict_types=1);

namespace App\Models\Antibody\Services;

use App\Models\Antibody\Brokers\EntryBroker;
use App\Models\Antibody\Entities\Entry;

readonly class EntryService
{
    public function __construct(
        private EntryBroker $broker = new EntryBroker(),
    ) {
    }

    public function findById(int $id): ?Entry
    {
        return Entry::build($this->broker->findById($id));
    }

    public function findByImmId(string $immId): ?Entry
    {
        return Entry::build($this->broker->findByImmId($immId));
    }

    public function findByPrimaryMatcherHash(string $hashHex): ?Entry
    {
        return Entry::build($this->broker->findByPrimaryMatcherHash($hashHex));
    }

    /**
     * @return Entry[]
     */
    public function findRecent(int $limit, ?int $beforeId = null): array
    {
        return Entry::buildArray($this->broker->findRecent($limit, $beforeId));
    }

    /**
     * Latest entries with per-row stats for the dashboard active-registry table.
     * Rows are stdClass (denormalised join, not the Entry entity shape).
     *
     * @return stdClass[]
     */
    public function findRecentWithStats(int $limit = 10): array
    {
        return $this->broker->findRecentWithStats($limit);
    }

    /**
     * Top entries by cache hits with per-row stats. Drives the landing page's
     * "top attacks" table.
     *
     * @return stdClass[]
     */
    public function findTopByCacheHits(int $limit = 10): array
    {
        return $this->broker->findTopByCacheHits($limit);
    }

    public function countActive(): int
    {
        return $this->broker->countActive();
    }

    /**
     * @return Entry[]
     */
    public function findFiltered(
        ?string $type = null,
        ?string $status = null,
        ?string $search = null,
        int $limit = 30,
        ?int $beforeId = null,
    ): array {
        return Entry::buildArray(
            $this->broker->findFiltered($type, $status, $search, $limit, $beforeId)
        );
    }

    public function countFiltered(
        ?string $type = null,
        ?string $status = null,
        ?string $search = null,
    ): int {
        return $this->broker->countFiltered($type, $status, $search);
    }

    /**
     * @return array<string, int>
     */
    public function countByType(): array
    {
        return $this->broker->countByType();
    }

    /**
     * @return array<string, int>
     */
    public function countByStatus(): array
    {
        return $this->broker->countByStatus();
    }

    /**
     * @return array<string, int>
     */
    public function countByVerdict(): array
    {
        return $this->broker->countByVerdict();
    }

    /**
     * Page-number paginated, multi-value filter list for the explorer UI.
     *
     * @param array<int, string> $types
     * @param array<int, string> $statuses
     * @param array<int, string> $verdicts
     * @return Entry[]
     */
    public function findPage(
        array $types = [],
        array $statuses = [],
        array $verdicts = [],
        ?string $search = null,
        ?string $range = null,
        ?int $sevMin = null,
        ?int $sevMax = null,
        ?string $publisher = null,
        int $perPage = 30,
        int $page = 1,
    ): array {
        return Entry::buildArray($this->broker->findPage(
            $types, $statuses, $verdicts, $search, $range, $sevMin, $sevMax, $publisher, $perPage, $page
        ));
    }

    /**
     * @param array<int, string> $types
     * @param array<int, string> $statuses
     * @param array<int, string> $verdicts
     */
    public function countAll(
        array $types = [],
        array $statuses = [],
        array $verdicts = [],
        ?string $search = null,
        ?string $range = null,
        ?int $sevMin = null,
        ?int $sevMax = null,
        ?string $publisher = null,
    ): int {
        return $this->broker->countAll(
            $types, $statuses, $verdicts, $search, $range, $sevMin, $sevMax, $publisher
        );
    }

    /**
     * Real per-antibody network-impact metrics, computed from event tables.
     *
     * @return array{
     *   cache_hits: int,
     *   agents_synced: int,
     *   blocks_made: int,
     *   value_protected_usd: string,
     *   ingestion: list<int>
     * }
     */
    public function impactFor(int $entryId): array
    {
        return $this->broker->impactFor($entryId);
    }
}
