<?php

declare(strict_types=1);

namespace Seo\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Seo\HostRole;
use Seo\Locales;
use Seo\SitemapEntry;

final class SitemapTest extends TestCase
{
    private const string HEAD = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";

    public function test_the_upfiles_sitemap_renders_exactly(): void
    {
        $this->withSite();
        $this->withSitemap([
            'http://localhost',
            '/login',
            new SitemapEntry('http://localhost/payment-proof', new DateTimeImmutable('2026-09-24T08:12:03+00:00')),
        ]);

        $this->get('/sitemap.xml')->assertOk()->assertContent(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
            <url><loc>http://localhost/</loc></url>
            <url><loc>http://localhost/login</loc></url>
            <url><loc>http://localhost/payment-proof</loc><lastmod>2026-09-24T08:12:03+00:00</lastmod></url>
            </urlset>

            XML);
    }

    public function test_locs_are_escaped_for_xml(): void
    {
        // `"` is not a URI character, so it is percent-encoded before XML escaping sees it.
        $this->withSite();
        $this->withSitemap(["/a'b\"c?x=1&y=2"]);

        $this->get('/sitemap.xml')->assertSee('<url><loc>http://localhost/a&apos;b%22c?x=1&amp;y=2</loc></url>', false);
    }

    public function test_locs_are_url_escaped_as_rfc_3986_asks_and_keep_existing_escapes(): void
    {
        $this->withSite();
        $this->withSitemap(['/über', '/a b?q=a b', '/%C3%A9t', '/a%zz']);

        $this->assertSame([
            'http://localhost/%C3%BCber',
            'http://localhost/a%20b?q=a%20b',
            'http://localhost/%C3%A9t',
            'http://localhost/a%25zz',
        ], $this->locs());
    }

    public function test_a_locs_query_keys_and_repeats_are_kept(): void
    {
        $this->withSite();
        $this->withSitemap(['/docs?v1.2=x&a+b=1&k=1&k=2&flag']);

        $this->assertSame(['http://localhost/docs?v1.2=x&a+b=1&k=1&k=2&flag'], $this->locs());
    }

    public function test_a_localized_loc_expands_to_every_locale_default_first_with_lastmod_copied_and_the_query_kept(): void
    {
        // The default is not first in codes: its bare URL still leads.
        $this->withSite();
        $this->withLocales(['fr', 'en', 'ar', 'es'], 'en');
        $lastModified = new DateTimeImmutable('2026-09-24T08:12:03+00:00');
        $this->withSitemap([new SitemapEntry('/faq', $lastModified), '/payment-proof?page=2&lang=de&v1.2=x', 'http://localhost']);

        $sitemap = iterator_to_array($this->seo()->sitemap(), false);

        $this->assertSame([
            'http://localhost/faq',
            'http://localhost/fr/faq',
            'http://localhost/ar/faq',
            'http://localhost/es/faq',
            'http://localhost/payment-proof?page=2&lang=de&v1.2=x',
            'http://localhost/fr/payment-proof?page=2&lang=de&v1.2=x',
            'http://localhost/ar/payment-proof?page=2&lang=de&v1.2=x',
            'http://localhost/es/payment-proof?page=2&lang=de&v1.2=x',
            'http://localhost/',
            'http://localhost/fr',
            'http://localhost/ar',
            'http://localhost/es',
        ], array_column($sitemap, 'loc'));
        $this->assertSame([...array_fill(0, 4, $lastModified), ...array_fill(0, 8, null)], array_column($sitemap, 'lastModified'));
    }

    public function test_a_loc_on_any_copy_expands_to_the_same_set(): void
    {
        $this->withSite();
        $this->withLocales(['en', 'fr', 'ar'], 'en');
        // The router decodes %61r to ar.
        $this->withSitemap(['/ar/faq', 'http://localhost/fr/', '/%61r/payment-proof']);

        $this->assertSame([
            'http://localhost/faq',
            'http://localhost/fr/faq',
            'http://localhost/ar/faq',
            'http://localhost/',
            'http://localhost/fr',
            'http://localhost/ar',
            'http://localhost/payment-proof',
            'http://localhost/fr/payment-proof',
            'http://localhost/ar/payment-proof',
        ], $this->locs());
    }

    public function test_an_unlocalized_loc_and_one_that_matches_no_route_are_listed_once(): void
    {
        $this->withSite();
        $this->withLocalizedRoutes(new Locales(['en', 'fr'], 'en'), static fn () => Route::get('terms', static fn () => 'terms'));
        Route::post('contact', static fn () => 'sent');
        $this->withSitemap(['/faq', '/nowhere', '/fr/nowhere', '/contact']);

        $this->assertSame([
            'http://localhost/faq',
            'http://localhost/nowhere',
            'http://localhost/fr/nowhere',
            'http://localhost/contact',
        ], $this->locs());
    }

    public function test_a_loc_an_earlier_unlocalized_route_serves_is_not_expanded_by_a_localized_catch_all(): void
    {
        $this->withSite();
        Route::get('pricing', static fn () => 'pricing');
        $this->withLocalizedRoutes(new Locales(['en', 'fr'], 'en'), static fn () => Route::get('{page}', static fn (string $page) => $page));
        $this->withSitemap(['/pricing', '/about']);

        $this->assertSame([
            'http://localhost/pricing',
            'http://localhost/about',
            'http://localhost/fr/about',
        ], $this->locs());
    }

    public function test_lastmod_is_atom_in_its_own_offset_and_omitted_when_null(): void
    {
        $this->withSite();
        $this->withSitemap([
            new SitemapEntry('/faq', new DateTimeImmutable('2026-09-24 10:12:03', new DateTimeZone('Europe/Paris'))),
            new SitemapEntry('/terms'),
        ]);

        $this->get('/sitemap.xml')
            ->assertSee('<url><loc>http://localhost/faq</loc><lastmod>2026-09-24T10:12:03+02:00</lastmod></url>', false)
            ->assertSee('<url><loc>http://localhost/terms</loc></url>', false);
    }

    public function test_locs_resolve_and_normalise_on_the_site_url(): void
    {
        $this->withSite(['url' => 'https://upfiles.test']);
        $this->withSitemap(['https://upfiles.test', 'http://UPFILES.test/faq#top', 'terms', '/pricing/', '/payment-proof?b=2&a=1']);

        $this->assertSame([
            'https://upfiles.test/',
            'https://upfiles.test/faq',
            'https://upfiles.test/terms',
            'https://upfiles.test/pricing',
            'https://upfiles.test/payment-proof?b=2&a=1',
        ], $this->locs());
    }

    public function test_a_loc_on_another_host_throws(): void
    {
        $this->withSite();
        $this->withSitemap(['/faq', 'http://go.test/x']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('http://go.test/x');

        iterator_to_array($this->seo()->sitemap());
    }

    public function test_a_broken_sitemap_is_a_server_error_not_an_empty_sitemap(): void
    {
        $this->withSite();
        $this->withSitemap(['http://go.test/x']);

        $this->get('/sitemap.xml')->assertStatus(500);
    }

    public function test_exactly_50000_urls_are_one_urlset_with_no_chunks(): void
    {
        $this->withSite();
        $this->withSitemap(array_map(static fn (int $i): string => "/p{$i}", range(1, 50_000)));

        $this->assertSame(50_000, substr_count((string)$this->get('/sitemap.xml')->assertOk()->getContent(), '<url>'));
        $this->get('/sitemap-1.xml')->assertNotFound();
    }

    public function test_past_50000_urls_sitemap_xml_indexes_chunks_of_50000(): void
    {
        $this->withSite();
        $this->withSitemap(array_map(static fn (int $i): string => "/p{$i}", range(1, 50_001)));

        $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml')->assertContent(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
            <sitemap><loc>http://localhost/sitemap-1.xml</loc></sitemap>
            <sitemap><loc>http://localhost/sitemap-2.xml</loc></sitemap>
            </sitemapindex>

            XML);

        $first = (string)$this->get('/sitemap-1.xml')->assertOk()->assertHeader('Cache-Control', 'max-age=3600, private')->getContent();
        $this->assertStringStartsWith(self::HEAD . "<url><loc>http://localhost/p1</loc></url>\n", $first);
        $this->assertStringEndsWith("<url><loc>http://localhost/p50000</loc></url>\n</urlset>\n", $first);
        $this->assertSame(50_000, substr_count($first, '<url>'));

        $this->get('/sitemap-2.xml')->assertOk()->assertContent(self::HEAD . "<url><loc>http://localhost/p50001</loc></url>\n</urlset>\n");

        foreach (['/sitemap-3.xml', '/sitemap-0.xml', '/sitemap-01.xml', '/sitemap-x.xml'] as $past) {
            $this->get($past)->assertNotFound();
        }

        $this->get('/robots.txt')->assertSee('Sitemap: http://localhost/sitemap.xml');
    }

    public function test_a_chunk_reads_the_entries_only_as_far_as_it_needs(): void
    {
        $this->withSite();
        $this->seo()->sitemapUsing(static function (): iterable {
            for ($i = 1; $i <= 50_001; $i++) {
                yield new SitemapEntry("/p{$i}");
            }

            throw new LogicException('read past the first chunk');
        });

        $this->get('/sitemap-1.xml')->assertOk();
    }

    public function test_other_hosts_redirect_permanently_to_the_index_host_sitemap(): void
    {
        $this->withSite();
        $this->withSitemap(['/faq']);

        foreach (['http://go.test', 'http://dl.test'] as $origin) {
            foreach (['/sitemap.xml', '/sitemap-2.xml'] as $path) {
                $this->get($origin . $path)
                    ->assertStatus(301)
                    ->assertHeader('Location', 'http://localhost' . $path);
            }
        }
    }

    public function test_the_resolvers_entries_come_first_then_the_config_locs_they_do_not_list(): void
    {
        // Route names resolve to paths on the Site's origin, whatever host the URL generator is on.
        $this->withSite(['url' => 'https://upfiles.test']);
        Route::get('terms', static fn () => 'terms')->name('terms');
        Route::getRoutes()->refreshNameLookups();
        $lastModified = new DateTimeImmutable('2026-09-24T08:12:03+00:00');
        config(['seo.sitemap' => ['terms', '/faq', 'https://upfiles.test/pricing', '/faq/']]);
        $this->withSitemap(['/payment-proof', new SitemapEntry('https://upfiles.test/faq', $lastModified)]);

        $sitemap = iterator_to_array($this->seo()->sitemap(), false);

        $this->assertSame([
            'https://upfiles.test/payment-proof',
            'https://upfiles.test/faq',
            'https://upfiles.test/terms',
            'https://upfiles.test/pricing',
        ], array_column($sitemap, 'loc'));
        $this->assertSame([null, $lastModified, null, null], array_column($sitemap, 'lastModified'));
    }

    public function test_a_resolver_entry_on_another_copy_of_a_localized_config_loc_replaces_its_set(): void
    {
        $this->withSite();
        $this->withLocales(['en', 'fr'], 'en');
        $lastModified = new DateTimeImmutable('2026-09-24T08:12:03+00:00');
        config(['seo.sitemap' => ['/faq']]);
        $this->withSitemap([new SitemapEntry('/fr/faq', $lastModified)]);

        $sitemap = iterator_to_array($this->seo()->sitemap(), false);

        $this->assertSame(['http://localhost/faq', 'http://localhost/fr/faq'], array_column($sitemap, 'loc'));
        $this->assertSame([$lastModified, $lastModified], array_column($sitemap, 'lastModified'));
    }

    public function test_a_config_loc_on_a_localized_route_expands_too(): void
    {
        $this->withSite();
        $this->withLocalizedRoutes(new Locales(['en', 'fr'], 'en'), static fn () => Route::get('terms', static fn () => 'terms')->name('terms'));
        $this->app->setLocale('fr');
        config(['seo.sitemap' => ['terms']]);

        $this->assertSame(['http://localhost/terms', 'http://localhost/fr/terms'], $this->locs());
    }

    public function test_a_config_route_name_on_another_domain_is_off_host(): void
    {
        $this->withSite();
        Route::domain('go.test')->get('x', static fn () => 'x')->name('go');
        Route::getRoutes()->refreshNameLookups();
        config(['seo.sitemap' => ['go']]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Sitemap loc [http://go.test/x] is not on localhost');

        iterator_to_array($this->seo()->sitemap());
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function notRouteNames(): iterable
    {
        yield 'an unknown name' => ['about', 'about'];
        yield 'a backed enum' => [HostRole::index, HostRole::class];
        yield 'an integer' => [1, '1'];
    }

    #[DataProvider('notRouteNames')]
    public function test_a_config_value_that_is_no_path_url_or_route_name_throws(mixed $value, string $shown): void
    {
        $this->withSite();
        config(['seo.sitemap' => ['/faq', $value]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("seo.sitemap: [{$shown}] is not a route name. Write paths with a leading '/', e.g. '/{$shown}'.");

        iterator_to_array($this->seo()->sitemap());
    }

    public function test_building_the_sitemap_leaves_the_current_routes_parameters_alone(): void
    {
        $this->withSite();
        $this->withLocalizedRoutes(new Locales(['en', 'fr'], 'en'), function (): void {
            Route::get('blog/{post}', fn (Request $request): string => count(iterator_to_array($this->seo()->sitemap(), false)) . ' ' . $request->route('post'));
        });
        $this->withSitemap(['/blog/other', '/fr/blog/third']);

        $this->get('/blog/hello')->assertOk()->assertContent('4 hello');
    }

    public function test_nothing_listed_is_404_and_unadvertised(): void
    {
        $this->withSite();

        $this->assertSame([], $this->locs());
        $this->get('/sitemap.xml')->assertNotFound();
        $this->get('/sitemap-1.xml')->assertNotFound();
        $this->get('/robots.txt')->assertOk()->assertContent("User-agent: *\nDisallow: /admin/\n");
    }

    public function test_a_resolver_listing_nothing_serves_an_empty_urlset(): void
    {
        $this->withSite();
        $this->withSitemap([]);

        $this->get('/sitemap.xml')->assertOk()->assertContent(self::HEAD . "</urlset>\n");
        $this->get('/robots.txt')->assertSee('Sitemap: http://localhost/sitemap.xml');
    }

    /** @return list<string> */
    private function locs(): array
    {
        return array_column(iterator_to_array($this->seo()->sitemap(), false), 'loc');
    }
}
