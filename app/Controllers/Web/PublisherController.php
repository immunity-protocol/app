<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Web\Antibody\Pagination;
use App\Models\Antibody\Brokers\EntryBroker;
use App\Models\Antibody\Brokers\PublisherBroker;
use App\Models\Event\Brokers\BlockEventBroker;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;

/**
 * Single-publisher detail page. Shows the publisher's metric strip
 * (rank, antibodies, blocks, value protected, total earned), a paginated
 * list of every antibody they've published, and the most recent matches
 * across all of those antibodies.
 *
 * Address-only routing for v1: ENS resolution is a redirect away once we
 * decide we want it. Keeps canonical URLs stable and side-steps the
 * "ENS-with-uppercase-letters" case folding question.
 */
final class PublisherController extends Controller
{
    private const PER_PAGE = 25;
    private const RECENT_MATCHES_LIMIT = 10;

    #[Get('/publisher/{address}')]
    public function show(string $address, Request $request): Response
    {
        // Normalize and validate before touching the DB. Bytea decode(hex)
        // would throw on garbage input, so we filter early to a clean 404.
        $hex = strtolower(ltrim($address, '0x'));
        if (!preg_match('/^[0-9a-f]{40}$/', $hex)) {
            return $this->render('errors/404', ['requestPath' => "/publisher/{$address}"])->withStatus(404);
        }

        $publisherBroker = new PublisherBroker();
        $publisher = $publisherBroker->findByAddressHex($hex);
        if ($publisher === null) {
            return $this->render('errors/404', ['requestPath' => "/publisher/{$address}"])->withStatus(404);
        }

        $entryBroker = new EntryBroker();
        $blocksBroker = new BlockEventBroker();

        $page = max(1, (int) ($request->query('page') ?? 1));
        $totalAntibodies = $entryBroker->countByPublisher($hex);
        $pagination = Pagination::compute($totalAntibodies, $page, self::PER_PAGE);
        $offset = ($pagination->page - 1) * self::PER_PAGE;
        $antibodies = $entryBroker->findPageByPublisher($hex, $offset, self::PER_PAGE);

        $rank = $publisherBroker->rankByEarnings($hex);
        $valueProtectedUsd = $publisherBroker->totalValueProtectedUsd($hex);
        $totalContributors = $publisherBroker->countAll();
        $recentMatches = $blocksBroker->findRecentByPublisher($hex, self::RECENT_MATCHES_LIMIT);

        return $this->render('publishers/show', [
            'publisher'         => $publisher,
            'addressHex'        => $hex,
            'rank'              => $rank,
            'totalContributors' => $totalContributors,
            'valueProtectedUsd' => $valueProtectedUsd,
            'antibodies'        => $antibodies,
            'recentMatches'     => $recentMatches,
            'pagination'        => $pagination,
            'totalAntibodies'   => $totalAntibodies,
        ]);
    }
}
