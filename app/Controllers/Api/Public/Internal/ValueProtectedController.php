<?php

declare(strict_types=1);

namespace App\Controllers\Api\Public\Internal;

use App\Models\Event\Brokers\CheckEventBroker;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Post;

/**
 * SDK-callable best-effort telemetry: an agent reports the USD at-risk value
 * of a transaction it just blocked. The indexer doesn't get this from the
 * chain (CheckSettled emits no value channel) so we accept it out of band.
 *
 * Endpoint:
 *   POST /v1/internal/value-protected
 *   Header: X-CRON-TOKEN: <secret>
 *   Body  : { "tx_hash": "0x...", "value_at_risk_usd": "12345.67" }
 *
 * Behavior:
 *   - Updates event.check_event.value_at_risk_usd for the matching tx_hash.
 *   - Mirrors the value into event.block_event.value_protected_usd for
 *     blocks tied to that tx_hash.
 *   - 200 with `{updated: N}` if at least one check_event row matched.
 *   - 202 with `{updated: 0}` if no row matched yet (out-of-order arrival;
 *     the SDK can retry, or skip if the indexer eventually catches up).
 *   - 400 on malformed body.
 */
final class ValueProtectedController extends Controller
{
    private CheckEventBroker $broker;

    #[Post('/internal/value-protected')]
    public function record(Request $request): Response
    {
        $body = $request->body();
        $txHash = $body->get('tx_hash');
        $value = $body->get('value_at_risk_usd');

        if (!is_string($txHash) || !preg_match('/^0x[0-9a-fA-F]{64}$/', $txHash)) {
            return Response::json(['error' => 'tx_hash must be 0x-prefixed 32-byte hex'], 400);
        }
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return Response::json(['error' => 'value_at_risk_usd must be numeric'], 400);
        }
        $valueStr = (string) $value;
        if (!preg_match('/^-?\d+(\.\d+)?$/', $valueStr)) {
            return Response::json(['error' => 'value_at_risk_usd must be a decimal number'], 400);
        }
        if ((float) $valueStr < 0) {
            return Response::json(['error' => 'value_at_risk_usd must be non-negative'], 400);
        }

        $this->broker ??= new CheckEventBroker();
        $updated = $this->broker->setValueAtRisk($txHash, $valueStr);

        $status = $updated > 0 ? 200 : 202;
        return Response::json(['updated' => $updated, 'value_at_risk_usd' => $valueStr], $status);
    }
}
