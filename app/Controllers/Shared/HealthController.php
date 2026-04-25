<?php

declare(strict_types=1);

namespace App\Controllers\Shared;

use Throwable;
use Zephyrus\Controller\Controller;
use Zephyrus\Core\App;
use Zephyrus\Data\Database;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;

final class HealthController extends Controller
{
    #[Get('/health')]
    public function index(): Response
    {
        $dbStatus = 'unconfigured';
        $config = App::getConfiguration()?->database;
        if ($config !== null) {
            try {
                Database::fromConfig($config)->selectValue('SELECT 1');
                $dbStatus = 'reachable';
            } catch (Throwable) {
                $dbStatus = 'unreachable';
            }
        }
        $payload = [
            'ok' => $dbStatus === 'reachable',
            'mode' => $_ENV['MODE'] ?? getenv('MODE') ?: null,
            'db' => $dbStatus,
        ];
        return Response::json($payload, $payload['ok'] ? 200 : 503);
    }
}
