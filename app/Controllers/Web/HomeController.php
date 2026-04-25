<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;

final class HomeController extends Controller
{
    #[Get('/')]
    public function index(): Response
    {
        return $this->render('home');
    }
}
