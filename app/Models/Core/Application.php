<?php

declare(strict_types=1);

namespace App\Models\Core;

use App\Models\Demo\PlaygroundSession;
use Throwable;
use Zephyrus\Core\App;
use Zephyrus\Core\ApplicationBuilder;
use Zephyrus\Formatting\Formatter;
use Zephyrus\Core\Config\SessionConfig;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Rendering\Asset;
use Zephyrus\Routing\Exception\RouteNotFoundException;
use Zephyrus\Routing\Exception\RouteParameterException;
use Zephyrus\Security\AuthGuardMiddleware;
use Zephyrus\Security\HeaderTokenGuard;
use Zephyrus\Security\PredicateAuthGuard;
use Zephyrus\Session\SessionMiddleware;

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
    public function __construct()
    {
        parent::__construct();
        // Asset manager: hashes file contents and appends ?v=<hash> to URLs
        // generated through the asset() helper. Browsers cache forever; a
        // changed file invalidates automatically.
        App::setAsset(new Asset(ROOT_DIR . '/public'));

        // Templates use {format('money', $v)} and {format('decimal', $v, 1)}
        // function syntax. Register a Latte function that dispatches to the
        // Formatter at render time (the formatter is set by ApplicationBuilder
        // during build, which runs before any request is handled).
        $latte = $this->renderEngine->getLatteEngine();
        $latte->addFunction('format', static function (string $name, mixed $value, mixed ...$args) {
            $formatter = App::getFormatter() ?? new Formatter();
            return match ($name) {
                'money'    => $formatter->money((float) $value, ...$args),
                'decimal'  => $formatter->decimal((float) $value, ...$args),
                'percent'  => $formatter->percent((float) $value, ...$args),
                'date'     => $formatter->date($value, ...$args),
                'datetime' => $formatter->datetime($value, ...$args),
                'time'     => $formatter->time($value, ...$args),
                'timeago'  => $formatter->timeago($value),
                default    => (string) $value,
            };
        });
    }

    protected function registerMiddleware(ApplicationBuilder $builder): ApplicationBuilder
    {
        // Session middleware is global so the /playground judge/admin tier
        // checks (which read session()) work end-to-end. Defaults are safe
        // for local dev; production should set secure=true behind HTTPS.
        $builder = $builder->withMiddleware(new SessionMiddleware(SessionConfig::fromArray([
            'name'     => 'IMMUNITY_SESSION',
            'lifetime' => 0,
            'sameSite' => 'Lax',
            'secure'   => false,
        ])));

        $cronToken = (string) ($_ENV['CRON_TOKEN'] ?? getenv('CRON_TOKEN') ?: '');
        if ($cronToken !== '') {
            $guard = new HeaderTokenGuard(
                expectedToken: $cronToken,
                headerName: 'X-CRON-TOKEN',
                bearerPrefix: '',
            );
            $builder = $builder->registerMiddleware('cron', new AuthGuardMiddleware($guard));
        }

        // /playground (judge tier): page + Section-1/2 endpoints. Granted by
        // posting PLAYGROUND_PASSWORD to /playground/login.
        $builder = $builder->registerMiddleware(
            'playground',
            new AuthGuardMiddleware(
                new PredicateAuthGuard(static fn () => PlaygroundSession::hasJudge()),
            ),
        );

        // Section 3 + destructive endpoints (kill agents, manual queue insert,
        // scenario triggers). Granted by posting ADMIN_PASSWORD to
        // /playground/admin-login on top of an existing judge session.
        $builder = $builder->registerMiddleware(
            'admin',
            new AuthGuardMiddleware(
                new PredicateAuthGuard(static fn () => PlaygroundSession::hasAdmin()),
            ),
        );

        // Pretty 404 for HTML clients; JSON-shaped 404 for API clients.
        // The framework's default returns plain text "Not Found", which is
        // jarring on a styled site. Negotiate by Accept header so curl /
        // Accept: application/json keep getting machine-readable bodies.
        $notFoundHandler = function (Throwable $exception, ?Request $request): Response {
            return $this->renderNotFound($request);
        };
        $builder = $builder
            ->withExceptionHandler(RouteNotFoundException::class, $notFoundHandler)
            ->withExceptionHandler(RouteParameterException::class, $notFoundHandler);

        return $builder;
    }

    private function renderNotFound(?Request $request): Response
    {
        $accept = $request?->headers()->get('accept', '') ?? '';
        $path = $request?->uri()->path() ?? '';

        $wantsJson = str_contains($accept, 'application/json')
            || str_contains($accept, '+json')
            || str_starts_with($path, '/v1/')
            || str_starts_with($path, '/api/');

        if ($wantsJson) {
            return Response::json([
                'error' => 'not found',
                'path'  => $path,
            ], 404);
        }

        $html = $this->renderEngine->render('errors/404', ['requestPath' => $path]);
        return Response::html((string) $html, 404);
    }
}
