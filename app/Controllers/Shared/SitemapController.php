<?php

declare(strict_types=1);

namespace App\Controllers\Shared;

use App\Models\Antibody\Services\EntryService;
use Zephyrus\Controller\Controller;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;

final class SitemapController extends Controller
{
    private EntryService $entries;

    #[Get('/sitemap.xml')]
    public function index(): Response
    {
        $this->entries ??= new EntryService();
        $base = 'https://immunity-protocol.com';
        $today = gmdate('Y-m-d');

        $coreUrls = [
            ['loc' => "{$base}/",            'changefreq' => 'daily',  'priority' => '1.0'],
            ['loc' => "{$base}/antibodies",  'changefreq' => 'hourly', 'priority' => '0.9'],
            ['loc' => "{$base}/dashboard",   'changefreq' => 'hourly', 'priority' => '0.8'],
            ['loc' => "{$base}/dex",         'changefreq' => 'weekly', 'priority' => '0.7'],
            ['loc' => "{$base}/feeds",       'changefreq' => 'monthly','priority' => '0.5'],
        ];

        $urls = '';
        foreach ($coreUrls as $u) {
            $loc = htmlspecialchars($u['loc'], ENT_XML1);
            $urls .= "<url>"
                . "<loc>{$loc}</loc>"
                . "<lastmod>{$today}</lastmod>"
                . "<changefreq>{$u['changefreq']}</changefreq>"
                . "<priority>{$u['priority']}</priority>"
                . "</url>";
        }

        // One <url> per published antibody. Capped at the first 5000 to keep
        // the response under sitemap protocol limits.
        foreach ($this->entries->findRecent(5000) as $e) {
            $loc = htmlspecialchars(
                "{$base}/antibody/" . rawurlencode($e->imm_id),
                ENT_XML1
            );
            $lastmod = gmdate('Y-m-d', strtotime($e->updated_at ?? $e->created_at));
            $urls .= "<url><loc>{$loc}</loc><lastmod>{$lastmod}</lastmod><changefreq>weekly</changefreq><priority>0.6</priority></url>";
        }

        $sitemap = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            . $urls
            . '</urlset>';

        return (new Response($sitemap, 200, ['content-type' => 'application/xml; charset=utf-8']))
            ->withHeader('Cache-Control', 'public, max-age=3600');
    }
}
