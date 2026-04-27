<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers\Web\Antibody;

use App\Controllers\Web\Antibody\Pagination;
use Tests\TestCase;

final class PaginationTest extends TestCase
{
    public function testZeroTotalShowsOnePage(): void
    {
        $p = Pagination::compute(0, 1, 30);
        self::assertSame(0, $p->total);
        self::assertSame(1, $p->page);
        self::assertSame(1, $p->totalPages);
        self::assertSame(0, $p->showingFrom);
        self::assertSame(0, $p->showingTo);
        self::assertFalse($p->hasPrev);
        self::assertFalse($p->hasNext);
        self::assertSame([1], $p->pageLinks);
    }

    public function testSinglePartialPage(): void
    {
        $p = Pagination::compute(19, 1, 30);
        self::assertSame(1, $p->totalPages);
        self::assertSame(1, $p->showingFrom);
        self::assertSame(19, $p->showingTo);
        self::assertFalse($p->hasNext);
    }

    public function testExactMultiple(): void
    {
        $p = Pagination::compute(60, 2, 30);
        self::assertSame(2, $p->totalPages);
        self::assertSame(31, $p->showingFrom);
        self::assertSame(60, $p->showingTo);
        self::assertFalse($p->hasNext);
        self::assertTrue($p->hasPrev);
        self::assertSame(1, $p->prevPage);
    }

    public function testOverflowPageClampsToLast(): void
    {
        $p = Pagination::compute(50, 99, 30);
        self::assertSame(2, $p->page);
        self::assertSame(50, $p->showingTo);
    }

    public function testSevenPagesShowsEverything(): void
    {
        $p = Pagination::compute(210, 4, 30);
        self::assertSame(7, $p->totalPages);
        self::assertSame([1, 2, 3, 4, 5, 6, 7], $p->pageLinks);
    }

    public function testManyPagesUsesEllipsis(): void
    {
        $p = Pagination::compute(1000, 50, 10);
        self::assertSame(100, $p->totalPages);
        self::assertSame(50, $p->page);
        self::assertSame([1, '...', 49, 50, 51, '...', 100], $p->pageLinks);
    }

    public function testEarlyPageOnlyTrailingEllipsis(): void
    {
        $p = Pagination::compute(1000, 2, 10);
        self::assertSame([1, 2, 3, '...', 100], $p->pageLinks);
    }

    public function testLatePageOnlyLeadingEllipsis(): void
    {
        $p = Pagination::compute(1000, 99, 10);
        self::assertSame([1, '...', 98, 99, 100], $p->pageLinks);
    }
}
