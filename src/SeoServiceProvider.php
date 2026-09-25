<?php

declare(strict_types=1);

namespace Seo;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Routing\Events\ResponsePrepared;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Arr;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;
use LogicException;
use Seo\Console\CheckCommand;
use Seo\Console\IndexNowCommand;
use Seo\Console\InstallCommand;
use Seo\Http\NoindexHosts;
use Seo\Http\SetLocale;
use Seo\View\Head;
use WeakMap;

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
        $this->app->scoped('seo.memo', static fn (): object => (object)['sites' => new WeakMap, 'pages' => new WeakMap, 'heads' => new WeakMap]);
    }

    public function boot(Router $router, Dispatcher $events): void
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

        Router::macro('localized', function (Locales $locales, Closure $routes): void {
            /** @var Router $this */
            $last = $this->hasGroupStack() ? Arr::last($this->getGroupStack()) : [];

            if (trim($this->getLastGroupPrefix(), '/') !== '' || isset($last[LocalizedRoute::ACTION])) {
                throw new LogicException('Route::localized() cannot sit inside a prefix group or another Route::localized(): the locale must be the first path segment.');
            }

            // Default last: first match wins, and a default route opening with a parameter ({page}) would catch /fr/…
            foreach ([...array_diff($locales->codes, [$locales->default]), $locales->default] as $code) {
                // A plain action key, not Route::metadata(): it survives group merging and route:cache on Laravel 12.
                $group = [
                    LocalizedRoute::ACTION => ['codes' => $locales->codes, 'default' => $locales->default, 'locale' => $code],
                    'middleware'           => [SetLocale::class],
                ];
                // Name-prefixed copies: route:cache throws on duplicate names, but tolerates an unnamed route's
                // bare `seo.{code}.`.
                $this->group($code === $locales->default ? $group : $group + ['prefix' => $code, 'as' => "seo.{$code}."], $routes);
            }

            // A route-level prefix (->prefix(), Route::prefix()->get()) lands before the group's; only the finished
            // URIs show it.
            foreach ($this->getRoutes()->getRoutes() as $route) {
                $localized = LocalizedRoute::of($route);

                if ($localized !== null && $localized->locale !== $localized->locales->default && $route->uri() !== $localized->locale && ! str_starts_with($route->uri(), "{$localized->locale}/")) {
                    throw new LogicException("Route::localized(): [{$route->uri()}] puts the locale after a route-level prefix; wrap the routes in Route::prefix(...)->group() instead.");
                }
            }
        });

        // SubstituteBindings (in `web`) runs before SetLocale: set the locale on match so a translated slug binds
        // under it.
        $router->matched(static function (RouteMatched $event): void {
            if (($localized = LocalizedRoute::of($event->route)) !== null) {
                /** @var Application $app */
                $app = Container::getInstance(); // under Octane, the request's sandbox
                $app->setLocale($localized->locale);
            }
        });

        // Any copy maps: action() finds whichever copy the route collection kept.
        $this->callAfterResolving('url', static function (UrlGenerator $url): void {
            $previous = $url->pathFormatter();

            $url->formatPathUsing(static function (string $path, ?Route $route = null) use ($previous): string {
                if (($localized = LocalizedRoute::of($route)) !== null) {
                    /** @var Application $app */
                    $app = Container::getInstance(); // the request's locale, or the one NotificationSender::withLocale() set
                    $locale = $app->getLocale();
                    $path = $localized->path($path, in_array($locale, $localized->locales->codes, true) ? $locale : $localized->locales->default);
                }

                return $previous($path, $route);
            });
        });

        if ($this->app->runningInConsole()) {
            $this->commands([CheckCommand::class, IndexNowCommand::class, InstallCommand::class]);
        }
    }
}
