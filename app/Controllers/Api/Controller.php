<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use Zephyrus\Controller\Controller as BaseController;
use Zephyrus\Routing\Attribute\RequiresEnv;

#[RequiresEnv('MODE', 'API')]
abstract class Controller extends BaseController
{
}
