<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Models\Antibody\Brokers\ChallengeBroker;
use App\Models\Core\NetworkConfig;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;

/**
 * /challenges — the challenge game: open + resolved disputes over antibodies.
 * Anyone can challenge a flag they judge false; the CRE diverse-model jury rules
 * invalid (struck down, publisher slashed) or valid (upheld, challenger loses
 * their bond). The "watch the immune system fight" view — the strongest
 * agentic-first showcase.
 */
final class ChallengesController extends Controller
{
    #[Get('/challenges')]
    public function index(): Response
    {
        $broker = new ChallengeBroker();
        return $this->render('challenges', [
            'challenges'  => $broker->findRecent(40),
            'total'       => $broker->countAll(),
            'open'        => $broker->countOpen(),
            'struck'      => $broker->countStruck(),
            'explorerUrl' => NetworkConfig::baseSepolia()->blockExplorerUrl,
        ]);
    }
}
