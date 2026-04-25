<?php

declare(strict_types=1);

namespace App\Controllers;

use Zephyrus\Controller\Controller;
use Zephyrus\Http\Response;
use Zephyrus\Rendering\RenderResponses;
use Zephyrus\Routing\Attribute\Get;

final class DashboardController extends Controller
{
    use RenderResponses;

    #[Get('/dashboard')]
    public function index(): Response
    {
        return $this->render('dashboard');
    }
}
