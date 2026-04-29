<?php

declare(strict_types=1);

namespace App\Controllers\Api\Public\Internal;

use App\Models\Demo\Brokers\CommandBroker;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Post;

/**
 * External threat-feed entry-point. Pipedream (or any cron-token-bearing
 * client) posts a structured threat report; we enqueue an
 * `external_threat_alert` command for an online publisher (picked at
 * random from demo.agent_heartbeat), which publishes an ADDRESS antibody
 * on its next dequeue tick. Returns 503 when no publisher is online.
 *
 * Endpoint:
 *   POST /v1/internal/threat-report
 *   Header: X-CRON-TOKEN: <secret>
 *   Body  : {
 *     "address":     "0x...",
 *     "severity":    90,
 *     "verdict":     "MALICIOUS"|"SUSPICIOUS",  (optional, default MALICIOUS)
 *     "reasoning":   "free text",
 *     "source_url":  "https://..."              (optional)
 *   }
 *
 * Response:
 *   202 Accepted with `{command_id, agent_id}` once enqueued.
 *   400 on malformed body.
 */
final class ThreatReportController extends Controller
{
    private const VALID_VERDICTS = ['MALICIOUS', 'SUSPICIOUS'];

    private CommandBroker $broker;

    #[Post('/internal/threat-report')]
    public function record(Request $request): Response
    {
        $body = $request->body();

        $address = $body->get('address');
        if (!is_string($address) || !preg_match('/^0x[0-9a-fA-F]{40}$/', $address)) {
            return Response::json(['error' => 'address must be 0x-prefixed 20-byte hex'], 400);
        }

        $severity = $body->get('severity');
        if (!is_int($severity) && !is_string($severity)) {
            return Response::json(['error' => 'severity must be an integer 0..100'], 400);
        }
        $severity = (int) $severity;
        if ($severity < 0 || $severity > 100) {
            return Response::json(['error' => 'severity must be 0..100'], 400);
        }

        $verdict = $body->get('verdict') ?? 'MALICIOUS';
        if (!is_string($verdict) || !in_array($verdict, self::VALID_VERDICTS, true)) {
            return Response::json(['error' => 'verdict must be MALICIOUS or SUSPICIOUS'], 400);
        }

        $reasoning = $body->get('reasoning');
        if (!is_string($reasoning) || trim($reasoning) === '') {
            return Response::json(['error' => 'reasoning must be a non-empty string'], 400);
        }

        $sourceUrl = $body->get('source_url');
        if ($sourceUrl !== null && !is_string($sourceUrl)) {
            return Response::json(['error' => 'source_url must be a string when present'], 400);
        }

        $payload = [
            'address'   => strtolower($address),
            'severity'  => $severity,
            'verdict'   => $verdict,
            'reasoning' => $reasoning,
            'source'    => 'pipedream',
        ];
        if ($sourceUrl !== null) {
            $payload['source_url'] = $sourceUrl;
        }

        $this->broker ??= new CommandBroker();
        $publisher = $this->broker->pickOnlinePublisher();
        if ($publisher === null) {
            return Response::json(['error' => 'no publisher online'], 503);
        }
        $commandId = $this->broker->enqueue($publisher, 'external_threat_alert', $payload);

        return Response::json([
            'command_id' => $commandId,
            'agent_id'   => $publisher,
        ], 202);
    }
}
