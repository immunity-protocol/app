<?php

declare(strict_types=1);

namespace App\Controllers\Api\Internal;

use App\Models\Antibody\Brokers\ProtectedTargetBroker;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;

/**
 * Live poll for the /protected page: the running attack-attempt feed (autoimmune
 * flags on protected addresses) plus the attempt/struck-down totals. Drives the
 * page without a reload.
 */
final class ProtectedController extends Controller
{
    #[Get('/protected/feed')]
    public function feed(): Response
    {
        $broker = new ProtectedTargetBroker();
        return Response::json([
            'stats' => [
                'attacks'  => $broker->countAttacks(),
                'defeated' => $broker->countDefeated(),
            ],
            'attempts' => array_map([$this, 'project'], $broker->findFlagAttempts(40)),
        ])->withHeader('Cache-Control', 'no-store');
    }

    private function project(\stdClass $r): array
    {
        return [
            'imm_id'        => (string) $r->imm_id,
            'target'        => $r->target,
            'status'        => (string) $r->status,
            'publisher'     => $r->publisher,
            'publisher_ens' => $r->publisher_ens,
            'created_at'    => (string) $r->created_at,
        ];
    }
}
