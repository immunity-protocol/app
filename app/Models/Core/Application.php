<?php

declare(strict_types=1);

namespace App\Models\Core;

/**
 * Application entry point.
 *
 * Controllers in app/Controllers/ are discovered automatically by the
 * parent Kernel. Override registerControllers(), registerMiddleware(),
 * or configureErrorHandlers() here when you need to customize the
 * bootstrap process.
 */
final class Application extends Kernel
{
}
