<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Models\Antibody\Services\EntryService;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;

final class DashboardController extends Controller
{
    #[Get('/dashboard')]
    public function index(): Response
    {
        // Latest 10 antibodies for the active-registry table on the dashboard.
        // The page also polls /api/v1/dashboard/activity to live-update this
        // list, but we still render the initial server-side list so there's
        // no "loading" flash on first paint.
        $recent = (new EntryService())->findRecentWithStats(10);
        return $this->render('dashboard', [
            'recentAntibodies' => $recent,
        ]);
    }
}
