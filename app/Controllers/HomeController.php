<?php

declare(strict_types=1);

namespace App\Controllers;

use Zephyrus\Controller\Controller;
use Zephyrus\Core\Config\Configuration;
use Zephyrus\Core\Config\DatabaseConfig;
use Zephyrus\Core\Kernel;
use Zephyrus\Data\Database;
use Zephyrus\Http\Response;
use Zephyrus\Rendering\RenderResponses;
use Zephyrus\Routing\Attribute\Get;

final class HomeController extends Controller
{
    use RenderResponses;

    #[Get('/')]
    public function index(): Response
    {
        return $this->render('home', [
            'frameworkVersion' => (new Kernel())->version(),
            'phpVersion' => PHP_VERSION,
            'environment' => env('APP_ENV', 'production'),
            'extensions' => $this->checkExtensions(),
            'database' => $this->checkDatabase(),
            'formatting' => $this->formattingShowcase(),
        ]);
    }

    /**
     * Build sample data for the Formatter showcase section.
     *
     * @return array<string, array{code: string, result: string}>
     */
    private function formattingShowcase(): array
    {
        $now = new \DateTimeImmutable();
        $yesterday = $now->modify('-1 day');

        return [
            ['code' => "format('money', 1499.99)", 'result' => format('money', 1499.99)],
            ['code' => "format('money', 2750.00, 'EUR')", 'result' => format('money', 2750.00, 'EUR')],
            ['code' => "format('decimal', 1234567.891, 2)", 'result' => format('decimal', 1234567.891, 2)],
            ['code' => "format('percent', 0.8542, 1)", 'result' => format('percent', 0.8542, 1)],
            ['code' => "format('date', \$now)", 'result' => format('date', $now)],
            ['code' => "format('date', \$now, 'full')", 'result' => format('date', $now, 'full')],
            ['code' => "format('time', \$now)", 'result' => format('time', $now)],
            ['code' => "format('datetime', \$now)", 'result' => format('datetime', $now)],
            ['code' => "format('timeago', \$yesterday)", 'result' => format('timeago', $yesterday)],
            ['code' => "format('duration', 7830)", 'result' => format('duration', 7830)],
            ['code' => "format('filesize', 1572864)", 'result' => format('filesize', 1572864)],
            ['code' => "format('list', ['PHP', 'Latte', 'PostgreSQL'])", 'result' => format('list', ['PHP', 'Latte', 'PostgreSQL'])],
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function checkExtensions(): array
    {
        return [
            'pdo' => extension_loaded('pdo'),
            'pdo_pgsql' => extension_loaded('pdo_pgsql'),
            'intl' => extension_loaded('intl'),
            'sodium' => extension_loaded('sodium'),
            'mbstring' => extension_loaded('mbstring'),
            'fileinfo' => extension_loaded('fileinfo'),
            'curl' => extension_loaded('curl'),
        ];
    }

    /**
     * @return array{connected: bool, message: string, version: string}
     */
    private function checkDatabase(): array
    {
        try {
            $config = \Zephyrus\Core\App::getConfiguration();
            if ($config === null || $config->database === null) {
                return [
                    'connected' => false,
                    'message' => 'No database configured',
                    'version' => '',
                ];
            }

            $db = Database::fromConfig($config->database);
            $version = $db->selectValue("SELECT version()");

            return [
                'connected' => true,
                'message' => 'Connected',
                'version' => is_string($version) ? $version : '',
            ];
        } catch (\Throwable $e) {
            return [
                'connected' => false,
                'message' => $e->getMessage(),
                'version' => '',
            ];
        }
    }
}
