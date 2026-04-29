<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Models\Core\SepoliaDexConfig;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;

final class DexController extends Controller
{
    #[Get('/dex')]
    public function index(): Response
    {
        $cfg = SepoliaDexConfig::default();

        // Pre-encode the config as JSON and as short address labels so the
        // Latte template stays free of PHP function calls.
        $configJson = json_encode([
            'chainId'                => $cfg->chainId,
            'rpcUrl'                 => $cfg->rpcUrl,
            'blockExplorerUrl'       => $cfg->blockExplorerUrl,
            'mirrorAddress'          => $cfg->mirrorAddress,
            'hookAddress'            => $cfg->hookAddress,
            'poolManagerAddress'     => $cfg->poolManagerAddress,
            'positionManagerAddress' => $cfg->positionManagerAddress,
            'swapRouterAddress'      => $cfg->swapRouterAddress,
            'quoterAddress'          => $cfg->quoterAddress,
            'tokenA'                 => $cfg->tokenA,
            'tokenB'                 => $cfg->tokenB,
            'currency0'              => $cfg->currency0,
            'currency1'              => $cfg->currency1,
            'fee'                    => $cfg->fee,
            'tickSpacing'            => $cfg->tickSpacing,
            'protectedPoolId'        => $cfg->protectedPoolId,
            'unprotectedPoolId'      => $cfg->unprotectedPoolId,
            'tokenALabel'            => $cfg->tokenALabel,
            'tokenBLabel'            => $cfg->tokenBLabel,
            'hasUnprotectedPool'     => $cfg->hasUnprotectedPool(),
        ], JSON_UNESCAPED_SLASHES);

        $shorten = static fn (string $a): string => strlen($a) > 10
            ? substr($a, 0, 6) . '...' . substr($a, -4)
            : $a;

        return $this->render('dex/index', [
            'cfg'             => $cfg,
            'configJson'      => $configJson,
            'shortHook'       => $shorten($cfg->hookAddress),
            'shortMirror'     => $shorten($cfg->mirrorAddress),
            'shortTokenA'     => $shorten($cfg->tokenA),
            'shortTokenB'     => $shorten($cfg->tokenB),
        ]);
    }
}
