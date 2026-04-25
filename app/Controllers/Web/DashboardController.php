<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;

final class DashboardController extends Controller
{
    #[Get('/dashboard')]
    public function index(): Response
    {
        return $this->render('dashboard');
    }
}
