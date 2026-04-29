<?php

declare(strict_types=1);

namespace App\Controllers\Shared\Feed;

use Zephyrus\Controller\Controller;
use App\Models\Antibody\Services\EntryService;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;

final class JsonFeedController extends Controller
{
    private EntryService $entries;

    #[Get('/feed/antibodies.json')]
    public function index(): Response
    {
        $this->entries ??= new EntryService();
        $entries = $this->entries->findRecent(50);

        $items = [];
        foreach ($entries as $e) {
            $items[] = [
                'id'             => $e->imm_id,
                'title'          => sprintf('%s - %s %s', $e->imm_id, $e->type, $e->verdict),
                'date_published' => gmdate('c', strtotime($e->created_at)),
                'content_text'   => sprintf(
                    'Antibody %s. Confidence %d, severity %d. Published by %s.',
                    $e->imm_id,
                    $e->confidence,
                    $e->severity,
                    $e->publisher_ens ?? '0x' . substr(bin2hex($e->publisher), 0, 8)
                ),
                'tags'           => array_filter([$e->type, $e->verdict, $e->status, $e->seed_source]),
            ];
        }

        return Response::json([
            'version'       => 'https://jsonfeed.org/version/1.1',
            'title'         => 'Immunity antibodies',
            'home_page_url' => 'https://immunity.example/antibodies',
            'feed_url'      => 'https://immunity.example/feed/antibodies.json',
            'items'         => $items,
        ])->withHeader('Content-Type', 'application/feed+json')
          ->withHeader('Cache-Control', 'public, max-age=60, stale-while-revalidate=300');
    }
}
