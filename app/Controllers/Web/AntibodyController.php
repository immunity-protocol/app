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

        // Grouped by distinct primary_matcher_hash: ONE row per threat (the
        // CVE-style registry unit), not one per corroborating antibody.
        $total = $entries->countThreats(
            $filters->types, $filters->statuses, $filters->verdicts,
            $filters->search, $filters->range,
            $filters->sevMin, $filters->sevMax, $filters->publisher
        );
        $rows = $entries->findThreatPage(
            $filters->types, $filters->statuses, $filters->verdicts,
            $filters->search, $filters->range,
            $filters->sevMin, $filters->sevMax, $filters->publisher,
            $filters->perPage, $filters->page
        );
        $pagination = Pagination::compute($total, $filters->page, $filters->perPage);

        // Facets tally distinct threats (matcher hashes), matching the grouped view.
        $statusCounts = $entries->countThreatsByStatus();
        $typeCounts = $entries->countThreatsByType();
        $verdictCounts = $entries->countThreatsByVerdict();

        return $this->render('antibodies/index', [
            'rows'       => $rows,
            'total'      => $total,
            'filters'    => $filters,
            'pagination' => $pagination,
            'corroborationK' => NetworkConfig::baseSepolia()->corroborationK,
            'threatTotal' => $total,
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

    #[Get('/threat/{id}')]
    public function threat(string $id): Response
    {
        $entries = new EntryService();
        // Route by Threat ID (IMM-T-YYYY-NNNN) first, then fall back to a raw
        // matcher hash. The threat is the centerpiece; its headline antibody is
        // the earliest corroborator.
        $threat = $entries->findThreatByThreatId($id) ?? $entries->findThreatByMatcherHash($id);
        if ($threat === null) {
            return $this->render('errors/404', ['requestPath' => "/threat/{$id}"])->withStatus(404);
        }
        $corroborators = $entries->findAllByPrimaryMatcherHash($threat->matcher_hash_hex);
        if ($corroborators === []) {
            return $this->render('errors/404', ['requestPath' => "/threat/{$id}"])->withStatus(404);
        }
        // Headline antibody for the envelope/evidence panels: the earliest
        // corroborator (findAllByPrimaryMatcherHash is oldest-first).
        return $this->renderThreat($threat, $corroborators[0], $entries);
    }

    #[Get('/antibody/{id}')]
    public function show(string $id): Response
    {
        $entries = new EntryService();
        $entry = $entries->findByImmId($id);
        if ($entry === null) {
            return $this->render('errors/404', ['requestPath' => "/antibody/{$id}"])->withStatus(404);
        }
        // Antibodies are corroborating sources, not headline rows: redirect to
        // the threat-centric view this antibody belongs to.
        $threat = $entry->primary_matcher_hash !== null
            ? $entries->findThreatByMatcherHash(bin2hex($entry->primary_matcher_hash))
            : null;
        if ($threat !== null) {
            return $this->redirect("/threat/{$threat->threat_id}");
        }
        // Legacy antibody with no matcher hash / no threat: render it standalone.
        $corroborationSet = $entry->primary_matcher_hash !== null
            ? $entries->findAllByPrimaryMatcherHash(bin2hex($entry->primary_matcher_hash))
            : [$entry];
        return $this->renderThreat(null, $entry, $entries, $corroborationSet);
    }

    /**
     * Threat-centric detail render shared by /threat/{id} and the legacy
     * /antibody/{id} fallback. $headline is the antibody whose envelope/evidence
     * panels drive the page (the earliest corroborator). $threat is the matcher
     * aggregate (null only for the legacy no-threat fallback).
     *
     * @param \stdClass|null            $threat
     * @param \App\Models\Antibody\Entities\Entry $headline
     * @param array<int, mixed>|null    $corroborationSet pre-fetched, or resolved here
     */
    private function renderThreat(
        ?\stdClass $threat,
        $headline,
        EntryService $entries,
        ?array $corroborationSet = null,
    ): Response {
        $mirrors = (new MirrorService())->findByEntryId($headline->id);
        $blocks = (new BlockEventService())->findRecentByEntryId($headline->id, 10);
        $publisher = (new PublisherService())->findByAddressHex(bin2hex($headline->publisher));
        $impact = $entries->impactFor($headline->id);
        // Total mirror chains we're configured to fan out to. Drives the
        // "X of N chains mirrored" denominator in the detail view.
        $mirrorChainsTotal = count(MirrorNetworkRegistry::default()->all());

        // Corroboration set: every publisher's antibody for the same
        // primary_matcher_hash. This is the hard-block story made visible and
        // the centerpiece of the threat view.
        if ($corroborationSet === null) {
            $corroborationSet = $headline->primary_matcher_hash !== null
                ? $entries->findAllByPrimaryMatcherHash(bin2hex($headline->primary_matcher_hash))
                : [$headline];
        }

        // Protected-set membership caps enforcement at advisory. Resolve the
        // matcher's target address (address-kind matchers only) against the set.
        $matcher = $headline->primary_matcher;
        $target = is_object($matcher) ? ($matcher->target ?? null) : null;
        $isProtected = is_string($target)
            && (new ProtectedTargetService())->isProtected($target);

        return $this->render('antibodies/show', [
            'id'                => $threat !== null ? $threat->threat_id : $headline->imm_id,
            'threat'            => $threat,
            'entry'             => $headline,
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
