<?php

declare(strict_types=1);

namespace App\Controllers\Shared\Feed;

use Zephyrus\Controller\Controller;
use App\Models\Antibody\Services\EntryService;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;

final class AtomController extends Controller
{
    private EntryService $entries;

    #[Get('/feed/antibodies.atom')]
    public function index(): Response
    {
        $this->entries ??= new EntryService();
        $entries = $this->entries->findRecent(50);

        $base = 'https://immunity-protocol.com';
        $feedId = $base . '/feed/antibodies.atom';
        $updated = $entries !== []
            ? gmdate('Y-m-d\TH:i:s\Z', strtotime($entries[0]->created_at))
            : gmdate('Y-m-d\TH:i:s\Z');

        $items = '';
        foreach ($entries as $e) {
            $entryUrl = $base . '/antibody/' . rawurlencode($e->imm_id);
            $publishedAt = gmdate('Y-m-d\TH:i:s\Z', strtotime($e->created_at));
            $title = htmlspecialchars($e->imm_id . ' - ' . $e->type . ' ' . $e->verdict, ENT_XML1);
            $authorName = htmlspecialchars(
                $e->publisher_ens ?? '0x' . substr(bin2hex($e->publisher), 0, 8),
                ENT_XML1
            );
            $summary = htmlspecialchars(
                sprintf(
                    'Antibody %s. Confidence %d, severity %d. Published by %s.',
                    $e->imm_id,
                    $e->confidence,
                    $e->severity,
                    $e->publisher_ens ?? '0x' . substr(bin2hex($e->publisher), 0, 8)
                ),
                ENT_XML1
            );

            $items .= "<entry>"
                . "<id>{$entryUrl}</id>"
                . "<title>{$title}</title>"
                . "<link href=\"{$entryUrl}\"/>"
                . "<published>{$publishedAt}</published>"
                . "<updated>{$publishedAt}</updated>"
                . "<author><name>{$authorName}</name></author>"
                . "<summary>{$summary}</summary>"
                . "</entry>";
        }

        $atom = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<feed xmlns="http://www.w3.org/2005/Atom">'
            . '<title>Immunity antibodies</title>'
            . '<subtitle>Recently published antibodies on the Immunity network.</subtitle>'
            . "<id>{$feedId}</id>"
            . "<link href=\"{$feedId}\" rel=\"self\"/>"
            . "<link href=\"{$base}/antibodies\"/>"
            . "<updated>{$updated}</updated>"
            . $items
            . '</feed>';

        return (new Response($atom, 200, ['content-type' => 'application/atom+xml; charset=utf-8']))
            ->withHeader('Cache-Control', 'public, max-age=60, stale-while-revalidate=300');
    }
}
