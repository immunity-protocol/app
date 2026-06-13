<?php

declare(strict_types=1);

namespace App\Controllers\Gateway;

use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Post;

/**
 * POST /evidence — the SDK's StorageClient signs a GatewayRequestV1 and POSTs
 * it here. The full pipeline (verify signature + payloadHash, confirm the
 * signer is a registered publisher, replay/quota guard, pin to Lighthouse,
 * return the CIDs) is wired in over subsequent commits; this is the skeleton.
 */
final class GatewayController extends Controller
{
    #[Post('/evidence')]
    public function evidence(Request $request): Response
    {
        return Response::json(['error' => 'not implemented'], 501);
    }
}
