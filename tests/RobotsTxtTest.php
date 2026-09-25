<?php

declare(strict_types=1);

namespace Seo\Tests;

use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Seo\HostRole;
use Seo\Site;

final class RobotsTxtTest extends TestCase
{
    private const array UPFILES_DISALLOW = ['/member/', '/admin/', '/horizon', '/file/', '/upload/'];

    public function test_the_index_host_body_ends_with_the_sitemap_line(): void
    {
        $this->assertSame(<<<'TXT'
            User-agent: *
            Disallow: /member/
            Disallow: /admin/
            Disallow: /horizon
            Disallow: /file/
            Disallow: /upload/

            Sitemap: https://upfiles.com/sitemap.xml

            TXT, $this->upfiles()->robotsTxt(HostRole::index, 'https://upfiles.com/sitemap.xml'));
    }

    public function test_crawl_and_noindex_hosts_get_the_same_rules_without_the_sitemap_line(): void
    {
        $expected = <<<'TXT'
            User-agent: *
            Disallow: /member/
            Disallow: /admin/
            Disallow: /horizon
            Disallow: /file/
            Disallow: /upload/

            TXT;

        $this->assertSame($expected, $this->upfiles()->robotsTxt(HostRole::crawl, 'https://upfiles.com/sitemap.xml'));
        $this->assertSame($expected, $this->upfiles()->robotsTxt(HostRole::noindex, 'https://upfiles.com/sitemap.xml'));
    }

    public function test_everything_allowed(): void
    {
        $site = new Site(name: 'x', url: 'https://x.test');

        $this->assertSame("User-agent: *\nDisallow:\n\nSitemap: https://cuty.io/sitemap.xml\n", $site->robotsTxt(HostRole::index, 'https://cuty.io/sitemap.xml'));
        $this->assertSame("User-agent: *\nDisallow:\n", $site->robotsTxt(HostRole::crawl, 'https://cuty.io/sitemap.xml'));
        $this->assertSame("User-agent: *\nDisallow:\n", $site->robotsTxt(HostRole::index, null));
    }

    public function test_the_sitemap_line_is_served_on_the_index_host_only(): void
    {
        $this->withSite();
        $this->withSitemap(['/faq']);

        $this->get('/robots.txt')->assertOk()->assertContent("User-agent: *\nDisallow: /admin/\n\nSitemap: http://localhost/sitemap.xml\n");

        foreach (['http://go.test', 'http://dl.test', 'http://www.localhost'] as $origin) {
            $this->get("{$origin}/robots.txt")->assertOk()->assertContent("User-agent: *\nDisallow: /admin/\n");
        }
    }

    public function test_the_index_host_wins_even_when_listed_as_noindex(): void
    {
        $site = $this->withSite(['noindex_hosts' => ['http://LOCALHOST/', null, '', 'https://DL.test:8443/x', 'dl.test', 'api.test']]);
        $this->withSitemap(['/faq']);

        $this->assertSame(['localhost', 'dl.test', 'api.test'], $site->noindexHosts);
        $this->assertSame(HostRole::index, $site->roleOf('LocalHost'));
        $this->assertSame(HostRole::noindex, $site->roleOf('DL.TEST'));
        $this->assertSame(HostRole::crawl, $site->roleOf('go.test'));
        $this->get('/robots.txt')->assertSee('Sitemap: http://localhost/sitemap.xml');
    }

    /** @return iterable<string, array{list<mixed>}> */
    public static function badPolicies(): iterable
    {
        yield 'no leading slash' => [['admin/']];
        yield 'empty' => [['']];
        yield 'a newline' => [["/admin/\nUser-agent: *"]];
        yield 'a carriage return' => [["/admin/\rx"]];
        // A crawler reads `#` as a comment, widening the rule to `/a`.
        yield 'a hash' => [['/a#b']];
        yield 'a space' => [['/a b']];
        yield 'a tab' => [["/a\tb"]];
        yield 'invalid UTF-8' => [["/a\xC3"]];
        yield 'not a string' => [[1]];
    }

    /** @param list<mixed> $disallow */
    #[DataProvider('badPolicies')]
    public function test_a_malformed_policy_throws(array $disallow): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Site::$disallow');

        new Site(name: 'x', url: 'https://x.test', disallow: $disallow);
    }

    public function test_wildcards_an_anchor_and_utf_8_are_valid_path_patterns(): void
    {
        $this->assertSame(
            "User-agent: *\nDisallow: /*.pdf$\nDisallow: /ü/\n",
            (new Site(name: 'x', url: 'https://x.test', disallow: ['/*.pdf$', '/ü/']))->robotsTxt(HostRole::crawl, 'https://upfiles.com/sitemap.xml'),
        );
    }

    public function test_the_body_has_no_bom_unix_line_endings_and_stays_small_with_many_rules(): void
    {
        $body = (new Site(name: 'x', url: 'https://x.test', disallow: array_map(static fn (int $i): string => "/p{$i}/", range(1, 10_000))))
            ->robotsTxt(HostRole::index, 'https://upfiles.com/sitemap.xml');

        $this->assertStringStartsWith('User-agent: *', $body);
        $this->assertStringNotContainsString("\r", $body);
        $this->assertStringEndsWith("\n", $body);
        $this->assertStringEndsNotWith("\n\n", $body);
        $this->assertLessThan(512_000, strlen($body));
    }

    public function test_the_response_is_plain_text_and_privately_cacheable(): void
    {
        $this->withSite();
        $this->withSitemap(['/faq']); // so the index host's body, and its ETag, differ from go.test's

        $response = $this->get('/robots.txt')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertHeader('Cache-Control', 'max-age=3600, private');

        $etag = (string)$response->headers->get('ETag');
        $this->assertNotSame('', $etag);

        $this->get('/robots.txt', ['If-None-Match' => $etag])->assertStatus(304)->assertContent('');
        $this->get('http://go.test/robots.txt', ['If-None-Match' => $etag])->assertOk();
    }

    public function test_the_routes_register_outside_any_middleware_group(): void
    {
        foreach (['seo.robots' => 'robots.txt', 'seo.sitemap' => 'sitemap.xml', 'seo.sitemap.chunk' => 'sitemap-{n}.xml', 'seo.indexnow' => 'indexnow-key.txt'] as $name => $uri) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertSame($uri, $route?->uri(), $name);
            $this->assertSame(['GET', 'HEAD'], $route->methods(), $name);
            $this->assertSame([], $route->gatherMiddleware(), $name);
        }
    }

    #[DefineEnvironment('withoutRoutes')]
    public function test_routes_false_registers_none_but_robots_txt_stays_out_of_maintenance_mode(): void
    {
        $this->withSite();

        foreach (['seo.robots', 'seo.sitemap', 'seo.sitemap.chunk', 'seo.indexnow'] as $name) {
            $this->assertFalse(Route::has($name), $name);
        }

        $this->get('/robots.txt')->assertNotFound();
        // An app serving its own robots.txt still must not 503 it.
        $this->assertContains('robots.txt', $this->app->make(PreventRequestsDuringMaintenance::class)->getExcludedPaths());
    }

    public function test_an_app_route_on_the_same_uri_wins(): void
    {
        $this->withSite();
        $this->withSitemap(['/faq']);
        Route::get('robots.txt', static fn () => response("User-agent: *\nDisallow: /\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']));

        $this->get('/robots.txt')->assertOk()->assertContent("User-agent: *\nDisallow: /\n");
        $this->get('/sitemap.xml')->assertOk();
    }

    public function test_robots_txt_answers_during_maintenance(): void
    {
        // A 503 robots.txt reads as disallow-all, and Google stops crawling.
        config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);
        $this->withSite();
        $this->withSitemap(['/faq']);
        $this->app->maintenanceMode()->activate([]);

        $this->get('/robots.txt')->assertOk()->assertContent("User-agent: *\nDisallow: /admin/\n\nSitemap: http://localhost/sitemap.xml\n");
        $this->get('/sitemap.xml')->assertStatus(503);
        $this->get('/faq')->assertStatus(503);
    }

    public function test_a_throwing_resolver_serves_the_permissive_body_uncached_and_reports(): void
    {
        Exceptions::fake();
        $this->seo()->siteUsing(static fn (): array => throw new RuntimeException('settings are down'));

        foreach (['http://localhost', 'http://dl.test'] as $origin) {
            $response = $this->get("{$origin}/robots.txt")
                ->assertOk()
                ->assertContent("User-agent: *\nDisallow:\n")
                ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
                ->assertHeaderMissing('ETag');

            $this->assertStringContainsString('no-store', (string)$response->headers->get('Cache-Control'));
            $this->assertStringNotContainsString('public', (string)$response->headers->get('Cache-Control'));
        }

        Exceptions::assertReported(static fn (RuntimeException $e): bool => $e->getMessage() === 'settings are down');
    }

    public function test_the_fallback_is_served_when_the_log_channel_cannot_write_either(): void
    {
        config(['logging.default' => 'single', 'logging.channels.single.path' => '/dev/null/not-a-dir/laravel.log']);
        $this->seo()->siteUsing(static fn (): array => throw new RuntimeException('settings are down'));

        $response = $this->get('/robots.txt')->assertOk()->assertContent("User-agent: *\nDisallow:\n");

        $this->assertStringContainsString('no-store', (string)$response->headers->get('Cache-Control'));
    }

    private function upfiles(): Site
    {
        return new Site(name: 'x', url: 'https://x.test', disallow: self::UPFILES_DISALLOW);
    }
}
