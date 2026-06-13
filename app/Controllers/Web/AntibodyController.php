<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Web\Antibody\AntibodyFilters;
use App\Controllers\Web\Antibody\Pagination;
use App\Models\Antibody\Services\EntryService;
use App\Models\Antibody\Services\MirrorService;
use App\Models\Antibody\Services\ProtectedTargetService;
use App\Models\Antibody\Services\PublisherService;
use App\Models\Core\MirrorNetworkRegistry;
use App\Models\Core\NetworkConfig;
use App\Models\Event\Services\BlockEventService;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;

final class AntibodyController extends Controller
{
    #[Get('/antibodies')]
    public function index(Request $request): Response
    {
        $filters = AntibodyFilters::fromRequest($request);
        $entries = new EntryService();

        $total = $entries->countAll(
            $filters->types, $filters->statuses, $filters->verdicts,
            $filters->search, $filters->range,
            $filters->sevMin, $filters->sevMax, $filters->publisher
        );
        $rows = $entries->findPage(
            $filters->types, $filters->statuses, $filters->verdicts,
            $filters->search, $filters->range,
            $filters->sevMin, $filters->sevMax, $filters->publisher,
            $filters->perPage, $filters->page
        );
        $pagination = Pagination::compute($total, $filters->page, $filters->perPage);

        $statusCounts = $entries->countByStatus();
        $typeCounts = $entries->countByType();
        $verdictCounts = $entries->countByVerdict();

        return $this->render('antibodies/index', [
            'rows'       => $rows,
            'total'      => $total,
            'filters'    => $filters,
            'pagination' => $pagination,
            'corroborationK' => NetworkConfig::baseSepolia()->corroborationK,
            'totals'     => [
                'probation'  => $statusCounts['probation']  ?? 0,
                'active'     => $statusCounts['active']      ?? 0,
                'challenged' => $statusCounts['challenged']  ?? 0,
                'expired'    => $statusCounts['expired']     ?? 0,
                'slashed'    => $statusCounts['slashed']     ?? 0,
            ],
            'facets' => [
                'type'    => $typeCounts,
                'status'  => $statusCounts,
                'verdict' => $verdictCounts,
            ],
        ]);
    }

    #[Get('/antibody/{id}')]
    public function show(string $id): Response
    {
        $entries = new EntryService();
        $entry = $entries->findByImmId($id);
        if ($entry === null) {
            return $this->render('errors/404', ['requestPath' => "/antibody/{$id}"])->withStatus(404);
        }
        $mirrors = (new MirrorService())->findByEntryId($entry->id);
        $blocks = (new BlockEventService())->findRecentByEntryId($entry->id, 10);
        $publisher = (new PublisherService())->findByAddressHex(bin2hex($entry->publisher));
        $impact = $entries->impactFor($entry->id);
        // Total mirror chains we're configured to fan out to. Drives the
        // "X of N chains mirrored" denominator in the detail view.
        $mirrorChainsTotal = count(MirrorNetworkRegistry::default()->all());

        // Corroboration set: the other publishers' antibodies for the same
        // primary_matcher_hash. This is the hard-block story made visible.
        $corroborationSet = [];
        if ($entry->primary_matcher_hash !== null) {
            $corroborationSet = $entries->findAllByPrimaryMatcherHash(
                bin2hex($entry->primary_matcher_hash)
            );
        }

        // Protected-set membership caps enforcement at advisory. Resolve the
        // matcher's target address (address-kind matchers only) against the set.
        $matcher = $entry->primary_matcher;
        $target = is_object($matcher) ? ($matcher->target ?? null) : null;
        $isProtected = is_string($target)
            && (new ProtectedTargetService())->isProtected($target);

        return $this->render('antibodies/show', [
            'id'                => $id,
            'entry'             => $entry,
            'mirrors'           => $mirrors,
            'blocks'            => $blocks,
            'publisher'         => $publisher,
            'impact'            => $impact,
            'mirrorChainsTotal' => $mirrorChainsTotal,
            'corroborationSet'  => $corroborationSet,
            'corroborationK'    => NetworkConfig::baseSepolia()->corroborationK,
            'isProtected'       => $isProtected,
        ]);
    }
}
