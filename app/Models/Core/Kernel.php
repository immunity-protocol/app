<?php

declare(strict_types=1);

namespace App\Models\Core;

use Dotenv\Dotenv;
use Zephyrus\Core\App;
use Zephyrus\Core\ApplicationBuilder;
use Zephyrus\Core\Config\Configuration;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Mailer\MailerConfig;
use Zephyrus\Rendering\LatteEngine;
use Zephyrus\Rendering\RenderConfig;
use Zephyrus\Routing\Router;

/**
 * Abstract application kernel that encapsulates the full bootstrap lifecycle.
 *
 * Controllers are discovered automatically from app/Controllers/ by default.
 * Override registerControllers() only if you need custom registration logic.
 *
 * In index.php:
 *
 *   (new Application())->run();
 */
abstract class Kernel
{
    protected Configuration $config;
    protected LatteEngine $renderEngine;

    public function __construct()
    {
        if (!defined('ROOT_DIR')) {
            define('ROOT_DIR', dirname(__DIR__, 3));
        }
        $this->boot();
    }

    /**
     * Handle the current HTTP request and send the response.
     */
    public function run(): void
    {
        $router = $this->registerControllers(new Router());

        $builder = ApplicationBuilder::create()
            ->withConfiguration($this->config, basePath: ROOT_DIR)
            ->withRouter($router);

        $builder = $this->configureErrorHandlers($builder);

        $renderEngine = $this->renderEngine;
        $builder = $builder->withControllerFactory(function (string $class) use ($renderEngine): object {
            $controller = new $class();
            if (method_exists($controller, 'setRenderEngine')) {
                $controller->setRenderEngine($renderEngine);
            }
            return $controller;
        });

        $builder = $this->registerMiddleware($builder);

        $app = $builder->build();

        $request = Request::fromGlobals(trustedProxies: $this->config->security->trustedProxies);
        $response = $app->handle($request);
        $response->send();
    }

    /**
     * Discover and register controllers with the router.
     *
     * By default, all concrete classes under app/Controllers/ with route
     * attributes (#[Get], #[Post], etc.) are registered automatically.
     *
     * Override this method to add manual registrations, apply groups, or
     * restrict discovery:
     *
     *   protected function registerControllers(Router $router): Router
     *   {
     *       return parent::registerControllers($router)
     *           ->group('/api', fn (Router $r) => $r
     *               ->controller(ApiController::class));
     *   }
     */
    protected function registerControllers(Router $router): Router
    {
        return $router->discoverControllers(
            namespace: 'App\\Controllers',
            directory: ROOT_DIR . '/app/Controllers',
        );
    }

    /**
     * Register global and named middleware.
     *
     * Override to add middleware. Default is a no-op.
     */
    protected function registerMiddleware(ApplicationBuilder $builder): ApplicationBuilder
    {
        return $builder;
    }

    /**
     * Configure custom exception handlers.
     *
     * Override to register custom exception handlers via the builder's
     * kernel builder. Default is a no-op.
     */
    protected function configureErrorHandlers(ApplicationBuilder $builder): ApplicationBuilder
    {
        return $builder;
    }

    /**
     * Bootstrap environment, configuration, and render engine.
     */
    private function boot(): void
    {
        Dotenv::createImmutable(ROOT_DIR)->safeLoad();

        // Default to WEB tier so single-process local dev (no MODE set) keeps
        // loading web routes. Containerized tiers set MODE explicitly.
        if (!isset($_ENV['MODE']) && getenv('MODE') === false) {
            $_ENV['MODE'] = 'WEB';
            putenv('MODE=WEB');
        }

        // Fly Postgres hands out a single DATABASE_URL secret. Explode it into
        // the discrete DB_* env vars that config.yml's !env directives expect.
        // Local docker-compose keeps using DB_HOST/DB_NAME/etc directly.
        Db::applyDatabaseUrl();

        $this->config = Configuration::fromYamlFile(ROOT_DIR . '/config.yml', [
            'render' => RenderConfig::class,
            'mailer' => MailerConfig::class,
            'project' => ProjectConfig::class,
        ]);

        /** @var RenderConfig $renderConfig */
        $renderConfig = $this->config->section('render') ?? RenderConfig::fromArray([]);
        $this->renderEngine = $renderConfig->createEngine(ROOT_DIR);
    }
}
