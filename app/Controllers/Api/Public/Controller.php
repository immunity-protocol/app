<?php

declare(strict_types=1);

namespace App\Controllers\Api\Public;

use Zephyrus\Controller\Controller as BaseController;
use Zephyrus\Routing\Attribute\RequiresEnv;
use Zephyrus\Routing\Attribute\Root;

/**
 * Base for the API tier's public developer surface. Lives behind the
 * api.immunity-protocol.com subdomain.
 *
 * Routes registered on subclasses are prefixed with /v1 from #[Root]
 * and only registered when MODE=API.
 */
#[Root('/v1')]
#[RequiresEnv('MODE', 'API')]
abstract class Controller extends BaseController
{
}
