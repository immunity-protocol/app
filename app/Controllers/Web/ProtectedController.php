<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Models\Antibody\Brokers\ProtectedTargetBroker;
use App\Models\Core\NetworkConfig;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;

/**
 * /protected — the protected set (the curated blue-chip safety rail) and the
 * live history of attempts to flag a protected address. Flagging USDC / WETH /
 * the canonical router is the autoimmune / DoS attack; a protected flag can
 * never hard-block and is struck down by the challenge game. This page shows the
 * rail and the defences holding.
 */
final class ProtectedController extends Controller
{
    #[Get('/protected')]
    public function index(): Response
    {
        $broker = new ProtectedTargetBroker();
        return $this->render('protected', [
            'targets'      => $broker->findProtectedWithStats(),
            'attempts'     => $broker->findFlagAttempts(40),
            'protectedN'   => $broker->countProtected(),
            'attacks'      => $broker->countAttacks(),
            'defeated'     => $broker->countDefeated(),
            'explorerUrl'  => NetworkConfig::baseSepolia()->blockExplorerUrl,
        ]);
    }
}
