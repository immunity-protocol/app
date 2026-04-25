<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use Zephyrus\Controller\Controller as BaseController;

/**
 * Base for /api/* endpoints. These load on every tier (WEB and API) so the
 * web frontend's live-polling JS can hit /api/* same-origin without a
 * reverse proxy. The internal cron endpoint sits below this class and
 * adds the cron-token middleware as its security boundary.
 */
abstract class Controller extends BaseController
{
}
