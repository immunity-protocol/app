<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Web\Antibody\Pagination;
use App\Models\Antibody\Services\PublisherService;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;

/**
 * Public network-contributors leaderboard. Lists every address that has
 * ever published an antibody, with derived totals (antibodies, blocks,
 * value protected, USDC earned). No filters, no search — just a paginated
 * roster ordered by total earned. Mirrors the chrome of /antibodies.
 */
final class PublishersController extends Controller
{
    private const PER_PAGE = 25;

    #[Get('/publishers')]
    public function index(Request $request): Response
    {
        $page = max(1, (int) ($request->query('page') ?? 1));

        $service = new PublisherService();
        $total = $service->countAll();
        $pagination = Pagination::compute($total, $page, self::PER_PAGE);

        // Re-derive offset from the (clamped) pagination page so we never
        // page off the end of the dataset.
        $offset = ($pagination->page - 1) * self::PER_PAGE;
        $rows = $service->listWithStatsPage($offset, self::PER_PAGE);

        return $this->render('publishers/index', [
            'rows'       => $rows,
            'total'      => $total,
            'pagination' => $pagination,
        ]);
    }
}
