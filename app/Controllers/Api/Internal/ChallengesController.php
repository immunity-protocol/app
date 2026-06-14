<?php

declare(strict_types=1);

namespace App\Controllers\Api\Internal;

use App\Models\Antibody\Brokers\ChallengeBroker;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;

/**
 * Live poll for the /challenges page: the running disputes feed (open + resolved)
 * plus the open/struck-down totals. Drives the page without a reload.
 */
final class ChallengesController extends Controller
{
    #[Get('/challenges/feed')]
    public function feed(): Response
    {
        $broker = new ChallengeBroker();
        return Response::json([
            'stats' => [
                'total'  => $broker->countAll(),
                'open'   => $broker->countOpen(),
                'struck' => $broker->countStruck(),
            ],
            'challenges' => $broker->findRecent(40),
        ])->withHeader('Cache-Control', 'no-store');
    }
}
