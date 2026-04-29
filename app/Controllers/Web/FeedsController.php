<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;

final class FeedsController extends Controller
{
    #[Get('/feeds')]
    public function index(): Response
    {
        return $this->render('feeds/index');
    }
}
