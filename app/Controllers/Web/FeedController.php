<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Models\Agent\Brokers\SocialPostBroker;
use App\Models\Core\NetworkConfig;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;

/**
 * /feed — the fake agent social network. Trader agents post benign chatter and
 * consume the feed; wolves plant poisoned content (prompt-injection / scam bait
 * from the curated incident catalog) which the network's SEMANTIC antibodies
 * catch. Renders posts with the author's ENS + avatar; malicious posts are
 * flagged. Infinite-scroll via /api/v1/feed/posts (same pattern as /dashboard).
 */
final class FeedController extends Controller
{
    #[Get('/feed')]
    public function index(): Response
    {
        $broker = new SocialPostBroker();
        return $this->render('feed', [
            'posts'          => $broker->findRecent(40),
            'total'          => $broker->countAll(),
            'maliciousTotal' => $broker->countMalicious(),
            'explorerUrl'    => NetworkConfig::baseSepolia()->blockExplorerUrl,
        ]);
    }
}
