<?php

declare(strict_types=1);

namespace App\Controllers\Web\Antibody;

/**
 * Computed pagination metadata for the explorer page.
 *
 * Page links use a compact pattern:
 *   - When totalPages <= 7: render every page (1..N)
 *   - Otherwise: render 1, 2, 3, [...], totalPages-1, totalPages with the
 *     current page surrounded by neighbors. The literal '...' string in
 *     `pageLinks` is the ellipsis sentinel; the template renders it inert.
 */
final readonly class Pagination
{
    /**
     * @param list<int|string> $pageLinks
     */
    private function __construct(
        public int $total,
        public int $page,
        public int $perPage,
        public int $totalPages,
        public int $showingFrom,
        public int $showingTo,
        public bool $hasPrev,
        public bool $hasNext,
        public int $prevPage,
        public int $nextPage,
        public array $pageLinks,
    ) {
    }

    public static function compute(int $total, int $page, int $perPage): self
    {
        $perPage = max(1, $perPage);
        $totalPages = $total === 0 ? 1 : (int) ceil($total / $perPage);
        $page = max(1, min($page, $totalPages));

        $showingFrom = $total === 0 ? 0 : (($page - 1) * $perPage) + 1;
        $showingTo = $total === 0 ? 0 : min($total, $page * $perPage);

        return new self(
            total: $total,
            page: $page,
            perPage: $perPage,
            totalPages: $totalPages,
            showingFrom: $showingFrom,
            showingTo: $showingTo,
            hasPrev: $page > 1,
            hasNext: $page < $totalPages,
            prevPage: max(1, $page - 1),
            nextPage: min($totalPages, $page + 1),
            pageLinks: self::buildPageLinks($page, $totalPages),
        );
    }

    /**
     * @return list<int|string>  ints are page numbers, '...' is an ellipsis sentinel
     */
    private static function buildPageLinks(int $page, int $totalPages): array
    {
        if ($totalPages <= 7) {
            return range(1, $totalPages);
        }
        $links = [1];
        $left = max(2, $page - 1);
        $right = min($totalPages - 1, $page + 1);
        if ($left > 2) {
            $links[] = '...';
        }
        for ($p = $left; $p <= $right; $p++) {
            $links[] = $p;
        }
        if ($right < $totalPages - 1) {
            $links[] = '...';
        }
        $links[] = $totalPages;
        return $links;
    }
}
