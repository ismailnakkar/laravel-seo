<?php

declare(strict_types=1);

namespace Seo;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Routing\Events\ResponsePrepared;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;
use Seo\Console\CheckCommand;
use Seo\Console\IndexNowCommand;
use Seo\Console\InstallCommand;
use Seo\Http\NoindexHosts;
use Seo\View\Head;

/** @internal Registered by package auto-discovery. */
final class SeoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/seo.php', 'seo');

        // Holds closures only, so a singleton is Octane-safe; per-request state lives in the scoped Memo.
        $this->app->singleton(Seo::class);
        // Scoped, not just Request-keyed: queue jobs share one console Request.
        $this->app->scoped(Memo::class);
    }

    public function boot(Dispatcher $events): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'seo');
        $this->publishes([__DIR__ . '/../config/seo.php' => $this->app->configPath('seo.php')], 'seo-config');
        $this->publishes([__DIR__ . '/../resources/views' => $this->app->resourcePath('views/vendor/seo')], 'seo-views');

        $this->callAfterResolving(BladeCompiler::class, static function (BladeCompiler $blade): void {
            $blade->componentNamespace('Seo\\View', 'seo');
            $blade->directive('seo', static fn (string $expression): string => "<?php app(\\Seo\\Seo::class)->page({$expression}); ?>");
        });

        // ResponsePrepared runs before route middleware reads the body; RequestHandled covers exception pages.
        $events->listen([ResponsePrepared::class, RequestHandled::class], static fn (ResponsePrepared|RequestHandled $event) => Head::fill($event->response, $event->request));

        // Global, so redirects and 404s on a noindex host carry the header too.
        $this->callAfterResolving(Kernel::class, static fn (HttpKernel $kernel) => $kernel->pushMiddleware(NoindexHosts::class));

        // Crawlers read a 503 robots.txt as disallow-all.
        PreventRequestsDuringMaintenance::except(['robots.txt']);

        if ($this->app->make('config')->get('seo.routes')) {
            // Before the app's routes, so an app route on the same URI wins.
            $this->loadRoutesFrom(__DIR__ . '/../routes/seo.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([CheckCommand::class, IndexNowCommand::class, InstallCommand::class]);
        }
    }
}
