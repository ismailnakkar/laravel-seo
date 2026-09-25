<?php

declare(strict_types=1);

namespace Seo\Tests;

use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Seo\HostRole;
use Seo\Http\NoindexHosts;
use Seo\Page;
use Seo\Seo;
use Seo\SitemapEntry;

final class HostTest extends TestCase
{
    public function test_noindex_hosts_get_the_header_and_no_other_host_does(): void
    {
        $this->withSite();

        $this->visit('http://dl.test/faq', new Page(title: 'FAQ'))->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('http://dl.test/robots.txt')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('http://dl.test/terms')->assertNotFound()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->visit('http://go.test/faq', new Page(title: 'FAQ'))->assertOk()->assertHeaderMissing('X-Robots-Tag');
        $this->visit('/faq', new Page(title: 'FAQ'))->assertOk()->assertHeaderMissing('X-Robots-Tag');
    }

    public function test_the_middleware_is_global_once_when_the_app_registers_it_too(): void
    {
        $this->withSite();
        $kernel = $this->app->make(Kernel::class)->pushMiddleware(NoindexHosts::class);

        $this->assertCount(1, array_keys($kernel->getGlobalMiddleware(), NoindexHosts::class, true));
        $this->assertSame(['noindex, nofollow'], $this->get('http://dl.test/nope')->headers->all('X-Robots-Tag'));
    }

    public function test_the_header_is_appended_to_an_existing_one(): void
    {
        // Engines apply the most restrictive of several: an existing value cannot make the host indexable.
        $this->withSite();
        Route::get('tagged', static fn () => response('ok')->header('X-Robots-Tag', 'max-image-preview:large'));

        $response = $this->get('http://dl.test/tagged')->assertOk();

        $this->assertSame(['max-image-preview:large', 'noindex, nofollow'], $response->headers->all('X-Robots-Tag'));
    }

    public function test_a_throwing_resolver_passes_the_response_through_and_is_reported(): void
    {
        Exceptions::fake();
        $this->seo()->siteUsing(static fn (): array => throw new RuntimeException('settings are down'));
        Route::get('tagged', static fn () => response('ok')->header('X-Robots-Tag', 'max-image-preview:large'));

        $response = $this->get('http://dl.test/tagged')->assertOk()->assertContent('ok');

        $this->assertSame(['max-image-preview:large'], $response->headers->all('X-Robots-Tag'));
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_a_noindex_host_spelled_as_a_fully_qualified_name_is_still_noindex(): void
    {
        $site = $this->withSite(['noindex_hosts' => ['dl.test', 'api.test.']]);

        $this->visit('http://dl.test./faq', new Page(title: 'FAQ'))
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
        $this->assertSame(HostRole::noindex, $site->roleOf('api.test'));
    }

    public function test_an_idn_host_is_stored_as_the_punycode_clients_send(): void
    {
        $site = $this->withSite(['url' => 'http://bücher.test', 'noindex_hosts' => ['dl-ü.test']]);
        $this->withSitemap(['/faq']);

        $this->assertSame('http://xn--bcher-kva.test', $site->url);
        $this->assertSame(HostRole::noindex, $site->roleOf('xn--dl--joa.test'));
        $this->get('http://xn--bcher-kva.test/sitemap.xml')->assertOk()->assertSee('<loc>http://xn--bcher-kva.test/faq</loc>', false);
        $this->get('http://xn--bcher-kva.test/robots.txt')->assertSee('Sitemap: http://xn--bcher-kva.test/sitemap.xml');
    }

    public function test_config_and_closures_resolve_from_the_current_container_as_under_octane(): void
    {
        $this->seo()->siteUsing(static fn (Application $app): array => ['image' => '/' . $app->getLocale()]);
        $this->seo()->sitemapUsing(static fn (Application $app): array => [new SitemapEntry('/' . $app->getLocale())]);

        // What Octane does per request: a clone of the booted app, with its own config.
        $sandbox = clone $this->app;
        $sandbox->instance('app', $sandbox);
        $sandbox->instance(Container::class, $sandbox);
        $sandbox->instance('config', clone $this->app['config']);
        $sandbox['config']->set('seo.name', 'Sandboxed');
        Container::setInstance($sandbox);

        try {
            $sandbox->setLocale('fr');
            $seo = $sandbox->make(Seo::class);
            $site = $seo->site(Request::create('/'));

            $this->assertSame($this->seo(), $seo);
            $this->assertSame(['Sandboxed', '/fr'], [$site->name, $site->image]);
            $this->assertSame(['http://localhost/fr'], array_column(iterator_to_array($seo->sitemap(), false), 'loc'));
        } finally {
            Container::setInstance($this->app);
        }
    }

    public function test_the_site_is_built_once_per_request(): void
    {
        $this->withSite();
        $builds = 0;
        $this->seo()->siteUsing(static function () use (&$builds): array {
            $builds++;

            return [];
        });

        $this->visit('http://dl.test/faq', new Page(title: 'FAQ'))->assertOk(); // middleware + head
        $this->assertSame(1, $builds);

        $this->get('http://dl.test/robots.txt')->assertOk(); // middleware + controller
        $this->assertSame(2, $builds);

        $this->withSitemap(['/faq']);
        $this->get('/sitemap.xml')->assertOk(); // middleware + controller + Seo::sitemap()
        $this->assertSame(3, $builds);
    }

    public function test_the_memo_is_per_request_object_and_absent_without_one(): void
    {
        $builds = 0;
        $this->seo()->siteUsing(static function () use (&$builds): array {
            $builds++;

            return [];
        });

        $request = Request::create('/');
        $this->assertSame($this->seo()->site($request), $this->seo()->site($request));
        $this->assertSame(1, $builds);

        $this->seo()->site(Request::create('/'));
        $this->assertSame(2, $builds);

        $this->seo()->site();
        $this->seo()->site();
        $this->assertSame(4, $builds);
    }

    public function test_site_using_resets_the_memo(): void
    {
        $request = Request::create('/');
        $this->seo()->site($request);

        $this->seo()->siteUsing(static fn (): array => ['name' => 'Renamed']);

        $this->assertSame('Renamed', $this->seo()->site($request)->name);
    }
}
