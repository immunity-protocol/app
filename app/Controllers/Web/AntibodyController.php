<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Web\Antibody\AntibodyFilters;
use App\Controllers\Web\Antibody\Pagination;
use App\Models\Antibody\Services\EntryService;
use App\Models\Antibody\Services\MirrorService;
use App\Models\Antibody\Services\PublisherService;
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
            'totals'     => [
                'active'  => $statusCounts['active']  ?? 0,
                'expired' => $statusCounts['expired'] ?? 0,
                'slashed' => $statusCounts['slashed'] ?? 0,
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
            return Response::html('<h1>404 - antibody not found</h1>', 404);
        }
        $mirrors = (new MirrorService())->findByEntryId($entry->id);
        $blocks = (new BlockEventService())->findRecentByEntryId($entry->id, 10);
        $publisher = (new PublisherService())->findByAddressHex(bin2hex($entry->publisher));
        $impact = $entries->impactFor($entry->id);
        return $this->render('antibodies/show', [
            'id'        => $id,
            'entry'     => $entry,
            'mirrors'   => $mirrors,
            'blocks'    => $blocks,
            'publisher' => $publisher,
            'impact'    => $impact,
        ]);
    }
}
