<?php

declare(strict_types=1);

namespace App\Controllers\Gateway;

use Zephyrus\Controller\Controller as BaseController;
use Zephyrus\Routing\Attribute\RequiresEnv;

/**
 * Base for the storage-gateway tier (MODE=GATEWAY). The gateway is a funded,
 * gated write endpoint: it verifies a publisher's signed evidence upload,
 * pins the artifacts to Lighthouse/IPFS, and returns the CIDs. It holds the
 * Lighthouse key (the SDK never does) and never decrypts or signs.
 *
 * Routes on subclasses are only registered when MODE=GATEWAY, mirroring how
 * the API tier scopes its controllers.
 */
#[RequiresEnv('MODE', 'GATEWAY')]
abstract class Controller extends BaseController
{
}
