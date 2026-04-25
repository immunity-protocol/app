<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\Event\Services\ActivityService;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;

final class ActivityController extends Controller
{
    private ActivityService $activity;

    #[Get('/api/activity')]
    public function index(Request $request): Response
    {
        $limit = (int) ($request->query('limit') ?? 25);
        $limit = max(1, min(100, $limit));
        $beforeId = $request->query('before_id');
        $beforeId = $beforeId === null ? null : (int) $beforeId;

        $this->activity ??= new ActivityService();
        $items = $this->activity->findRecent($limit, $beforeId);

        return Response::json([
            'count'  => count($items),
            'items'  => $items,
        ])->withHeader('Cache-Control', 'public, max-age=4, stale-while-revalidate=10');
    }
}
