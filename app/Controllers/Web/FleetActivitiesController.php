<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Web\Antibody\Pagination;
use App\Models\Demo\Brokers\AgentActivityBroker;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;

/**
 * Paginated history of every agent activity row recorded by the demo
 * fleet. The /dashboard panel shows the latest 50 in real time; this
 * page is the deep archive. No filters, no search, just pagination.
 */
final class FleetActivitiesController extends Controller
{
    private const PER_PAGE = 30;

    #[Get('/fleet-activities')]
    public function index(Request $request): Response
    {
        $page = max(1, (int) ($request->query('page') ?? 1));

        $broker = new AgentActivityBroker();
        $total = $broker->countAll();
        $pagination = Pagination::compute($total, $page, self::PER_PAGE);

        $offset = ($pagination->page - 1) * self::PER_PAGE;
        $activity = $broker->findPage($offset, self::PER_PAGE);

        return $this->render('fleet-activities/index', [
            'activity'   => $activity,
            'total'      => $total,
            'pagination' => $pagination,
        ]);
    }
}
