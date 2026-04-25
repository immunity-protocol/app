<?php

declare(strict_types=1);

namespace App\Controllers\Api\Feed;

use App\Controllers\Api\Controller;
use App\Models\Antibody\Services\EntryService;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;

final class RssController extends Controller
{
    private EntryService $entries;

    #[Get('/feed/antibodies.rss')]
    public function index(): Response
    {
        $this->entries ??= new EntryService();
        $entries = $this->entries->findRecent(50);

        $now = gmdate('D, d M Y H:i:s') . ' GMT';
        $items = '';
        foreach ($entries as $e) {
            $pubDate = gmdate('D, d M Y H:i:s', strtotime($e->created_at)) . ' GMT';
            $title = htmlspecialchars($e->imm_id . ' - ' . $e->type . ' ' . $e->verdict, ENT_XML1);
            $description = htmlspecialchars(
                sprintf(
                    'Antibody %s. Confidence %d, severity %d. Published by %s.',
                    $e->imm_id,
                    $e->confidence,
                    $e->severity,
                    $e->publisher_ens ?? '0x' . substr(bin2hex($e->publisher), 0, 8)
                ),
                ENT_XML1
            );
            $items .= "<item>"
                . "<title>$title</title>"
                . "<description>$description</description>"
                . "<guid isPermaLink=\"false\">{$e->imm_id}</guid>"
                . "<pubDate>$pubDate</pubDate>"
                . "</item>";
        }

        $rss = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<rss version="2.0"><channel>'
            . '<title>Immunity antibodies</title>'
            . '<link>https://immunity.example/antibodies</link>'
            . '<description>Recently published antibodies on the Immunity network.</description>'
            . "<lastBuildDate>$now</lastBuildDate>"
            . $items
            . '</channel></rss>';

        return (new Response($rss, 200, ['content-type' => 'application/rss+xml; charset=utf-8']))
            ->withHeader('Cache-Control', 'public, max-age=60, stale-while-revalidate=300');
    }
}
