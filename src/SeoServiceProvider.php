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
class SeoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/seo.php', 'seo');

        // Octane-safe singleton: it holds closures, never resolved values.
        $this->app->singleton(Seo::class);
        // Scoped, so an Octane request or a queue job (whose console Request is shared) starts empty; keyed by Request
        // within a scope.
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

        // ResponsePrepared fires before route middleware (a response cache) reads the body; RequestHandled catches the
        // exception handler's pages, which the router never prepares.
        $events->listen([ResponsePrepared::class, RequestHandled::class], static fn (ResponsePrepared|RequestHandled $event) => Head::fill($event->response, $event->request));

        // Global, so a noindex host's redirects, 404s and files carry the header too. Skipped when already listed.
        $this->callAfterResolving(Kernel::class, static fn (HttpKernel $kernel) => $kernel->pushMiddleware(NoindexHosts::class));

        // A 503 robots.txt reads as disallow-all, whoever serves it.
        PreventRequestsDuringMaintenance::except(['robots.txt']);

        if ($this->app->make('config')->get('seo.routes')) {
            // Before the app's routes, so one of its own on the same URI replaces it.
            $this->loadRoutesFrom(__DIR__ . '/../routes/seo.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([CheckCommand::class, IndexNowCommand::class, InstallCommand::class]);
        }
    }
}
