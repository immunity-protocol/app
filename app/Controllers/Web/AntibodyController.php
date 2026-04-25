<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Models\Antibody\Services\EntryService;
use App\Models\Antibody\Services\MirrorService;
use App\Models\Antibody\Services\PublisherService;
use App\Models\Event\Services\BlockEventService;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;

final class AntibodyController extends Controller
{
    #[Get('/antibodies')]
    public function index(): Response
    {
        $entries = new EntryService();
        $rows = $entries->findRecent(30);
        $totals = [
            'active'   => $entries->countFiltered(status: 'active'),
            'expired'  => $entries->countFiltered(status: 'expired'),
            'slashed'  => $entries->countFiltered(status: 'slashed'),
        ];
        return $this->render('antibodies/index', [
            'rows'   => $rows,
            'totals' => $totals,
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
        return $this->render('antibodies/show', [
            'id'        => $id,
            'entry'     => $entry,
            'mirrors'   => $mirrors,
            'blocks'    => $blocks,
            'publisher' => $publisher,
        ]);
    }
}
