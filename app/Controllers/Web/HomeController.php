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
        // Live activity feed on the landing page. 10 most recent antibodies.
        $recent = (new EntryService())->findRecent(10);
        return $this->render('home', [
            'recentAntibodies' => $recent,
        ]);
    }
}
