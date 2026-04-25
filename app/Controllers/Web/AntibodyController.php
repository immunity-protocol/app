<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;

final class AntibodyController extends Controller
{
    #[Get('/antibodies')]
    public function index(): Response
    {
        return $this->render('antibodies/index');
    }

    #[Get('/antibody/{id}')]
    public function show(string $id): Response
    {
        return $this->render('antibodies/show', ['id' => $id]);
    }
}
