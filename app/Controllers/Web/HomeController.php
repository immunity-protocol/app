<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Models\Antibody\Services\EntryService;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;

final class HomeController extends Controller
{
    #[Get('/')]
    public function index(): Response
    {
        // Top antibodies on the landing - the "what's hot" view (highest
        // cache hits = most matched threats). Dashboard shows latest-by-id
        // for the live "what just happened" view.
        $topAntibodies = (new EntryService())->findTopByCacheHits(10);
        return $this->render('home', [
            'topAntibodies' => $topAntibodies,
        ]);
    }
}
