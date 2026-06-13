<?php

declare(strict_types=1);

namespace App\Controllers\Gateway;

use App\Models\Gateway\Brokers\GatewayUploadBroker;
use App\Models\Gateway\GatewayException;
use App\Models\Gateway\GatewayRequestVerifier;
use App\Models\Gateway\GatewayService;
use App\Models\Gateway\PublisherRegistry;
use App\Models\Gateway\Storage\LighthouseStore;
use Throwable;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Post;

/**
 * POST /evidence — the SDK's StorageClient signs a GatewayRequestV1 and POSTs
 * it here. The gateway verifies the signature + payloadHash, confirms the
 * signer is a registered (bonded) publisher, replay/quota-guards, pins the
 * public envelope and encrypted context to Lighthouse, and returns the CIDs.
 *
 * It never decrypts and holds no signing key — only the Lighthouse API key.
 */
final class GatewayController extends Controller
{
    private const int DEFAULT_QUOTA_PER_HOUR = 60;

    #[Post('/evidence')]
    public function evidence(Request $request): Response
    {
        $service = new GatewayService(
            new GatewayRequestVerifier(),
            new PublisherRegistry(),
            new LighthouseStore(),
            new GatewayUploadBroker(),
            self::quotaPerHour(),
        );

        try {
            $body = $service->process($request->body()->raw(), self::nowMs());
        } catch (GatewayException $e) {
            return Response::json(['error' => $e->getMessage()], $e->httpStatus);
        } catch (Throwable $e) {
            // Upstream failure (RPC, Lighthouse): fail-closed, don't leak internals.
            error_log('[gateway] /evidence upstream error: ' . $e->getMessage());
            return Response::json(['error' => 'upstream storage error'], 502);
        }

        return Response::json($body, 201);
    }

    private static function quotaPerHour(): int
    {
        $raw = (string) ($_ENV['GATEWAY_QUOTA_PER_HOUR'] ?? getenv('GATEWAY_QUOTA_PER_HOUR') ?: '');
        $value = (int) $raw;
        return $value > 0 ? $value : self::DEFAULT_QUOTA_PER_HOUR;
    }

    private static function nowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }
}
