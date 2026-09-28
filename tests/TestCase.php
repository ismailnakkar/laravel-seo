<?php

declare(strict_types=1);

namespace Seo\Tests;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\PendingCommand;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Seo\Page;
use Seo\Seo;
use Seo\SeoServiceProvider;
use Seo\Site;
use Seo\SitemapEntry;
use Seo\Tests\Fixtures\SetLocale;
use Symfony\Component\HttpFoundation\Response;

abstract class TestCase extends BaseTestCase
{
    /** The index host; dl.test is noindex, other hosts crawl. */
    protected const string URL = 'http://localhost';

    protected const array PAGES = ['/', '/faq', '/payment-proof', '/reset-password'];

    /** @var (Closure(Request): ?Page)|null */
    protected ?Closure $fixturePage = null;

    /** The pages' @section('title'), the head's fallback without a Page. */
    protected ?string $fixtureTitle = null;

    protected function setUp(): void
    {
        // Laravel 12 keeps this static between tests; each app must start from its own provider's boot.
        PreventRequestsDuringMaintenance::flushState();

        parent::setUp();

        Http::preventStrayRequests();
    }

    protected function getPackageProviders($app): array
    {
        return [SeoServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.url', self::URL);
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32))); // web group encrypts cookies
        $app['config']->set('view.paths', [__DIR__ . '/Fixtures/views']);
        $app['config']->set('database.default', 'testing');
    }

    /** For #[DefineEnvironment]: runs before the providers boot. */
    protected function withoutRoutes(Application $app): void
    {
        $app['config']->set('seo.routes', false);
    }

    /** Testbench wraps these in `web`, as an app's routes/web.php. */
    protected function defineWebRoutes($router): void
    {
        foreach (static::PAGES as $path) {
            $router->get($path, $this->renderFixturePage(...));
        }
    }

    /** Relative URLs hit the index host, not the host the previous request left url() on. */
    protected function prepareUrlForRequest($uri)
    {
        return is_string($uri) && str_starts_with($uri, '/') ? self::URL . $uri : parent::prepareUrlForRequest($uri);
    }

    /** <html lang> follows the app locale, as an app layout's does. */
    protected function renderFixturePage(Request $request, Seo $seo): View
    {
        $page = $this->fixturePage === null ? null : ($this->fixturePage)($request);

        if ($page !== null) {
            $seo->page(...get_object_vars($page));
        }

        return view('page', ['title' => $this->fixtureTitle, 'lang' => $this->app->getLocale()]);
    }

    /** @return TestResponse<Response> */
    protected function visit(string $url, ?Page $page = null, ?string $title = null): TestResponse
    {
        $this->fixturePage = $page === null ? null : static fn (): Page => $page;
        $this->fixtureTitle = $title;

        return $this->get($url);
    }

    /**
     * Narrows Laravel's PendingCommand|int: console output is always mocked here.
     *
     * @param  string  $command
     * @param  array<string, mixed>  $parameters
     */
    public function artisan($command, $parameters = []): PendingCommand
    {
        $pending = parent::artisan($command, $parameters);
        assert($pending instanceof PendingCommand);

        return $pending;
    }

    protected function seo(): Seo
    {
        return $this->app->make(Seo::class);
    }

    /** @param  array<string, mixed>  $config */
    protected function withSite(array $config = []): Site
    {
        config(['seo' => [
            'name'          => 'UpFiles',
            'image'         => '/img/og-image.png',
            'disallow'      => ['/admin/'],
            'noindex_hosts' => ['dl.test'],
            ...$config,
        ] + config('seo')]);

        return $this->seo()->site();
    }

    /** @param list<SitemapEntry|string> $entries */
    protected function withSitemap(array $entries): void
    {
        $entries = array_map(static fn (SitemapEntry|string $entry): SitemapEntry => is_string($entry) ? new SitemapEntry($entry) : $entry, $entries);

        $this->seo()->sitemapUsing(static fn (): array => $entries);
    }

    /** @param list<string> $locs */
    protected static function sitemapXml(string $root, array $locs): string
    {
        $entry = $root === 'urlset' ? 'url' : 'sitemap';
        $entries = implode('', array_map(static fn (string $loc): string => "<{$entry}><loc>{$loc}</loc></{$entry}>", $locs));

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<{$root} xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">{$entries}</{$root}>";
    }

    /**
     * Routes $paths per language ($codes[0] bare, others under their code) and fakes alternatesUsing() for just these.
     * Routes already on these URIs are replaced.
     *
     * @param  list<string>  $codes  the first is the default
     * @param  list<string>  $paths
     * @param  Closure|null  $action  null: the fixture page
     */
    protected function withAlternates(array $codes = ['en', 'fr', 'ar', 'es'], array $paths = self::PAGES, ?Closure $action = null): void
    {
        $default = $codes[0];
        $router = $this->app->make(Router::class);
        $uris = [];

        // Default last, as localization packages do: a default route opening with {page} would catch /fr/….
        foreach ([...array_slice($codes, 1), $default] as $code) {
            foreach ($paths as $path) {
                $uri = ($code === $default ? '' : "{$code}/") . ltrim($path, '/');
                $uris[] = $router->middleware(['web', SetLocale::class . ":{$code}"])->get($uri, $action ?? $this->renderFixturePage(...))->uri();
            }
        }

        $this->seo()->alternatesUsing(static function (Route $route, string $path) use ($codes, $default, $uris): ?array {
            if (! in_array($route->uri(), $uris, true)) {
                return null;
            }

            // The router matched the decoded path, so /%66r/faq is the fr copy.
            [$first, $rest] = explode('/', ltrim($path, '/'), 2) + [1 => ''];
            $code = rawurldecode($first);

            if ($code === $default || ! in_array($code, $codes, true)) {
                [$code, $rest] = [$default, ltrim($path, '/')];
            }

            $to = static fn (string $c): string => $c === $default ? "/{$rest}" : rtrim("/{$c}/{$rest}", '/');

            return ['path' => $to($code), 'alternates' => array_combine($codes, array_map($to, $codes))];
        });
    }
}
