<?php

declare(strict_types=1);

namespace Seo;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Contracts\Session\Middleware\AuthenticatesSessions;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Routing\Events\ResponsePrepared;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Arr;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;
use LogicException;
use Seo\Console\CheckCommand;
use Seo\Console\IndexNowCommand;
use Seo\Console\InstallCommand;
use Seo\Http\ApplyLocale;
use Seo\Http\NoindexHosts;
use Seo\Http\RedirectToDefaultCopy;
use Seo\Http\ResolveLocale;
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

        // Set on match, before any route middleware: a localized route outside `web`, or with remember_locale off, gets
        // its locale too. In register(), so providers' boot-time listeners (an error tracker) see the locale and name.
        $this->app->make(Router::class)->matched(static function (RouteMatched $event): void {
            if (($localized = LocalizedRoute::of($event->route)) === null) {
                return;
            }

            /** @var Application $app */
            $app = Container::getInstance(); // under Octane, the request's sandbox
            $app->setLocale($localized->locale);

            if ($localized->locale !== $localized->locales->default) {
                // Stored as seo.{code}.name, unique for route:cache; the matched copy answers to the route's own name.
                $event->route->action['as'] = $localized->name($event->route);
            }
        });
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

        if (Locales::configured() !== null && $this->app->make('config')->get('seo.remember_locale') !== false) {
            // ResolveLocale straight after StartSession: CSRF, signature, throttle and auth refusals then speak the
            // visitor's language. ApplyLocale, which reads the user and can answer, straight after AuthenticateSession;
            // a priority list without it (an app's own priority()) ranks ApplyLocale last, after every listed check.
            $this->callAfterResolving(Kernel::class, static function (HttpKernel $kernel): void {
                if (array_key_exists('web', $kernel->getMiddlewareGroups())) {
                    $kernel->appendMiddlewareToGroup('web', ResolveLocale::class)
                        ->appendMiddlewareToGroup('web', ApplyLocale::class)
                        ->addToMiddlewarePriorityAfter(StartSession::class, ResolveLocale::class)
                        ->addToMiddlewarePriorityAfter(AuthenticatesSessions::class, ApplyLocale::class);
                }
            });

            // Whatever `routes` says: this is the language switcher, not a crawler file.
            $this->loadRoutesFrom(__DIR__ . '/../routes/locale.php');
        }

        // A 503 robots.txt reads as disallow-all, whoever serves it.
        PreventRequestsDuringMaintenance::except(['robots.txt']);

        if ($this->app->make('config')->get('seo.routes')) {
            // Before the app's routes, so one of its own on the same URI replaces it.
            $this->loadRoutesFrom(__DIR__ . '/../routes/seo.php');
        }

        // mixed, not Closure: a v0.2 call, Locales first, gets this message instead of a TypeError.
        Router::macro('localized', function (mixed $routes): void {
            if (! $routes instanceof Closure) {
                throw new LogicException("Route::localized() takes only the routes closure since v0.3: set the languages in config('seo.locales') as a list of codes, default first.");
            }

            /** @var Router $this */
            $locales = Locales::configured();

            // One language: plain routes, no copies and no marker.
            if ($locales === null) {
                $this->group([], $routes);

                return;
            }

            $last = $this->hasGroupStack() ? Arr::last($this->getGroupStack()) : [];

            if (trim($this->getLastGroupPrefix(), '/') !== '' || isset($last[LocalizedRoute::ACTION])) {
                throw new LogicException('Route::localized() cannot sit inside a prefix group or another Route::localized(): the locale must be the first path segment.');
            }

            // A plain action key, not Route::metadata(): it survives group merging and route:cache on Laravel 12.
            $marker = static fn (string $code): array => [LocalizedRoute::ACTION => ['codes' => $locales->codes, 'default' => $locales->default, 'locale' => $code]];
            $others = array_values(array_diff($locales->codes, [$locales->default]));
            // Held, not counted: Laravel 13 lists domain routes first, so the new copies are not the list's tail. Holding
            // them also keeps their object ids from being reused.
            $before = $this->getRoutes()->getRoutes();
            $seen = array_flip(array_map(spl_object_id(...), $before));
            // Keyed by domain and URI: an app's own GET on a redirect's URI stays, where adding the redirect would
            // replace it.
            $taken = $this->getRoutes()->get('GET');

            foreach ($others as $code) {
                // Name-prefixed copies: route:cache throws on duplicate names, but tolerates an unnamed route's
                // bare `seo.{code}.`.
                $this->group($marker($code) + ['prefix' => $code, 'as' => "seo.{$code}."], $routes);
            }

            // The default's own prefix answers 301 to its copy (/en/terms → /terms, en the default): one redirect per
            // GET page, taken from its first copy since the default's are not registered yet. A fallback gets none, so
            // any other path under that prefix still 404s.
            foreach ($this->getRoutes()->getRoutes() as $copy) {
                $localized = LocalizedRoute::of($copy);

                if (isset($seen[spl_object_id($copy)]) || $localized?->locale !== $others[0] || $copy->isFallback || ! in_array('GET', $copy->methods(), true)) {
                    continue;
                }

                $uri = rtrim("{$locales->default}/{$localized->unprefixedUri($copy)}", '/');

                if (isset($taken[$copy->getDomain() . $uri])) {
                    continue;
                }

                // The domain in the action, not ->domain() after: the collection files a route by domain as it adds
                // it. A leading backslash, as Route::redirect() has: a `namespace` group would otherwise prefix the class.
                $this->get($uri, ['uses' => '\\' . RedirectToDefaultCopy::class, RedirectToDefaultCopy::ACTION => $locales->default] + array_filter(['domain' => $copy->getDomain()]))
                    ->where($copy->wheres);
            }

            // Default last: first match wins, and a default route opening with a parameter ({page}) would catch /fr/…
            // and the redirects under the default's own prefix.
            $this->group($marker($locales->default), $routes);

            // A route-level prefix (->prefix(), Route::prefix()->get()) lands before the group's; only the finished
            // URIs show it.
            foreach ($this->getRoutes()->getRoutes() as $route) {
                $localized = LocalizedRoute::of($route);

                if ($localized === null || $localized->locale === $localized->locales->default) {
                    continue;
                }

                $opensWithLocale = $route->uri() === $localized->locale || str_starts_with($route->uri(), "{$localized->locale}/");

                if (! $opensWithLocale) {
                    throw new LogicException("Route::localized(): [{$route->uri()}] puts the locale after a route-level prefix; wrap the routes in Route::prefix(...)->group() instead.");
                }
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
