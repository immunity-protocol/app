<?php

declare(strict_types=1);

namespace App\Models\Core;

use Zephyrus\Core\App;
use Zephyrus\Core\ApplicationBuilder;
use Zephyrus\Formatting\Formatter;
use Zephyrus\Rendering\Asset;
use Zephyrus\Security\AuthGuardMiddleware;
use Zephyrus\Security\HeaderTokenGuard;

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
        $cronToken = (string) ($_ENV['CRON_TOKEN'] ?? getenv('CRON_TOKEN') ?: '');
        if ($cronToken !== '') {
            $guard = new HeaderTokenGuard(
                expectedToken: $cronToken,
                headerName: 'X-CRON-TOKEN',
                bearerPrefix: '',
            );
            $builder = $builder->registerMiddleware('cron', new AuthGuardMiddleware($guard));
        }
        return $builder;
    }
}
