<?php

declare(strict_types=1);

namespace App\Controllers\Api\Internal;

use Zephyrus\Controller\Controller as BaseController;
use Zephyrus\Routing\Attribute\RequiresEnv;
use Zephyrus\Routing\Attribute\Root;

/**
 * Base for the WEB tier's internal API surface (consumed by the home page's
 * live-polling JS). Same-origin under app.immunity-protocol.com so no CORS.
 *
 * Routes registered on subclasses are prefixed with /api/v1 from #[Root]
 * and only registered when MODE=WEB.
 */
#[Root('/api/v1')]
#[RequiresEnv('MODE', 'WEB')]
abstract class Controller extends BaseController
{
}
