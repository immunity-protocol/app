<?php

declare(strict_types=1);

namespace App\Controllers\Api\Internal;

use App\Controllers\Api\Controller as ApiController;
use Zephyrus\Routing\Attribute\Middleware;

/**
 * Base for /internal/* endpoints. Inherits the API tier #[RequiresEnv] from
 * App\Controllers\Api\Controller and additionally requires the cron-token
 * middleware to pass.
 */
#[Middleware('cron')]
abstract class Controller extends ApiController
{
}
