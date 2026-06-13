<?php

declare(strict_types=1);

namespace App\Models\Gateway;

use RuntimeException;

/**
 * A request-level rejection carrying the HTTP status the gateway should return.
 * Every verification/gate failure is fail-closed: it raises this with a 4xx.
 */
final class GatewayException extends RuntimeException
{
    public function __construct(
        public readonly int $httpStatus,
        string $message,
    ) {
        parent::__construct($message);
    }
}
