<?php

declare(strict_types=1);

namespace App\Controllers\Api\Public\Internal;

use App\Controllers\Api\Public\Controller as PublicController;
use Zephyrus\Routing\Attribute\Middleware;

/**
 * Base for cron-only endpoints under /v1/internal/*.
 *
 * Inherits #[Root("/v1")] and #[RequiresEnv("MODE","API")] from the public
 * controller, then layers the cron-token middleware on top so the route is
 * 401 unless X-CRON-TOKEN matches the deployed CRON_TOKEN secret.
 */
#[Middleware('cron')]
abstract class Controller extends PublicController
{
}
