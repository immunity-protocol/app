<?php

declare(strict_types=1);

namespace App\Controllers\Api\Internal;

use App\Models\Agent\Brokers\SocialPostBroker;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;

/**
 * Scroll-loading + live-poll feed for the /feed page. No cursor → latest window
 * (first paint + live prepend); `before_id` → the next page of older posts.
 */
final class FeedController extends Controller
{
    private const WINDOW = 40;
    private const PAGE = 30;

    #[Get('/feed/posts')]
    public function posts(Request $request): Response
    {
        $broker = new SocialPostBroker();
        $beforeId = $request->query('before_id');
        $sinceId = $request->query('since_id');

        if (is_string($beforeId) && $beforeId !== '') {
            $posts = $broker->findOlder((int) $beforeId, self::PAGE);
        } elseif (is_string($sinceId) && $sinceId !== '') {
            $posts = $broker->findSince((int) $sinceId, self::WINDOW);
        } else {
            $posts = $broker->findRecent(self::WINDOW);
        }
        return Response::json(['posts' => $posts])->withHeader('Cache-Control', 'no-store');
    }
}
