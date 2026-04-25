<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use Zephyrus\Controller\Controller as BaseController;
use Zephyrus\Rendering\RenderResponses;
use Zephyrus\Routing\Attribute\RequiresEnv;

#[RequiresEnv('MODE', 'WEB')]
abstract class Controller extends BaseController
{
    use RenderResponses;
}
