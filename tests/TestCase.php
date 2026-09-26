<?php

declare(strict_types=1);

namespace Seo\Tests;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\PendingCommand;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Seo\Locales;
use Seo\Page;
use Seo\Seo;
use Seo\SeoServiceProvider;
use Seo\Site;
use Seo\SitemapEntry;
use Symfony\Component\HttpFoundation\Response;

abstract class TestCase extends BaseTestCase
{
    /** The index host; unlisted hosts (go.test) crawl, dl.test is noindex. */
    protected const string URL = 'http://localhost';

    /** Rendered with the fixture layout on every host. */
    protected const array PAGES = ['/', '/faq', '/payment-proof', '/reset-password'];

    /** @var (Closure(Request): ?Page)|null null sets no Page */
    protected ?Closure $fixturePage = null;

    /** The pages' @section('title'): the head's fallback when no Page is passed. */
    protected ?string $fixtureTitle = null;

    protected function setUp(): void
    {
        // Static, and Laravel 12 keeps it between tests: each app must start from its own provider's boot.
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
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32))); // the web group encrypts cookies
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

    /** Relative URLs hit the index host, not whichever host the previous request left url() on. */
    protected function prepareUrlForRequest($uri)
    {
        return is_string($uri) && str_starts_with($uri, '/') ? self::URL . $uri : parent::prepareUrlForRequest($uri);
    }

    /** A controller action, reusable for extra paths; <html lang> follows the app locale, as an app layout's does. */
    protected function renderFixturePage(Request $request, Seo $seo): View
    {
        $page = $this->fixturePage === null ? null : ($this->fixturePage)($request);

        if ($page !== null) {
            $seo->page(...get_object_vars($page));
        }

        return view('page', ['title' => $this->fixtureTitle, 'lang' => $this->app->getLocale()]);
    }

    /**
     * GET $url with the fixture pages rendering $page, or no Page and $title as the fallback.
     *
     * @return TestResponse<Response>
     */
    protected function visit(string $url, ?Page $page = null, ?string $title = null): TestResponse
    {
        $this->fixturePage = $page === null ? null : static fn (): Page => $page;
        $this->fixtureTitle = $title;

        return $this->get($url);
    }

    /**
     * Laravel returns PendingCommand|int; the console output is always mocked here.
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

    /** @param  array<string, mixed>  $config  seo.* keys */
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
     * The fixture pages inside Route::localized(), replacing the unlocalized routes on the same URIs.
     *
     * @param  list<string>  $codes  the first is the default
     */
    protected function withLocales(array $codes = ['en', 'fr', 'ar', 'es']): Locales
    {
        $this->withLocalizedRoutes($codes, function (Router $router): void {
            foreach (static::PAGES as $path) {
                $router->get($path, $this->renderFixturePage(...));
            }
        });

        return new Locales($codes, $codes[0]);
    }

    /** @param list<string> $codes the first is the default */
    protected function withLocalizedRoutes(array $codes, Closure $routes): void
    {
        config(['seo.locales' => $codes]);
        $router = $this->app->make(Router::class);
        $router->middleware('web')->group(static fn (Router $router) => $router->localized($routes));
        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }

    /** The fixture User's table. */
    protected function createUsersTable(): void
    {
        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->default('');
            $table->string('locale', 20)->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    /** The fixture Admin's table, which has no `locale`. */
    protected function createAdminsTable(): void
    {
        Schema::create('admins', static function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });
    }
}
