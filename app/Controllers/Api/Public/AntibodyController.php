<?php

declare(strict_types=1);

namespace App\Controllers\Api\Public;

use App\Models\Antibody\Services\EntryService;
use App\Models\Antibody\Services\MirrorService;
use App\Models\Antibody\Services\PublisherService;
use App\Models\Event\Services\BlockEventService;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;

final class AntibodyController extends Controller
{
    private EntryService $entries;
    private MirrorService $mirrors;
    private BlockEventService $blocks;
    private PublisherService $publishers;

    #[Get('/antibody/{immId}')]
    public function show(string $immId): Response
    {
        $this->entries ??= new EntryService();
        $entry = $this->entries->findByImmId($immId);
        if ($entry === null) {
            return Response::json(['error' => 'not found', 'imm_id' => $immId], 404);
        }
        $this->mirrors ??= new MirrorService();
        $this->blocks ??= new BlockEventService();
        $this->publishers ??= new PublisherService();

        $publisherHex = bin2hex($entry->publisher);
        return Response::json([
            'entry'         => $entry,
            'mirrors'       => $this->mirrors->findByEntryId($entry->id),
            'recent_blocks' => $this->blocks->findRecentByEntryId($entry->id, 10),
            'publisher'     => $this->publishers->findByAddressHex($publisherHex),
        ])->withHeader('Cache-Control', 'public, max-age=15, stale-while-revalidate=30');
    }
}
