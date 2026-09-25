<?php

declare(strict_types=1);

namespace Seo\Tests;

use Closure;
use DateTimeImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Seo\Locales;
use Seo\Page;
use Seo\ParsedPage;
use Seo\Robots;
use Seo\SitemapEntry;
use Seo\Testing\SeoAssertions;
use Seo\Tests\Fixtures\NegotiateLocale;

final class SeoAssertionsTest extends TestCase
{
    use SeoAssertions;

    /** The index host's robots.txt. */
    private const string ROBOTS = "User-agent: *\nDisallow: /admin/\n\nSitemap: http://localhost/sitemap.xml\n";

    /** A crawlable head for http://localhost/case. */
    private const string HEAD = '<title>Case</title><link rel="canonical" href="http://localhost/case">';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withSite();
    }

    public function test_a_revisit_after_a_set_cookie_loops(): void
    {
        Route::middleware('web')->group(static function (): void {
            Route::get('revisit', static fn (Request $request) => $request->cookies->has('seen') ? 'arrived' : redirect('/revisit/b')->withCookie(cookie('seen', '1')));
            Route::get('revisit/b', static fn () => redirect('/revisit'));
        });

        $this->assertFailsWith(
            'Redirect loop after 2 hops: http://localhost/revisit 302 → http://localhost/revisit/b 302 → http://localhost/revisit',
            fn () => $this->followRedirectChain('/revisit'),
        );
    }

    public function test_a_login_on_one_hop_is_a_guest_on_the_next_cookieless_hop(): void
    {
        Route::middleware('web')->group(static function (): void {
            Route::get('login/1', static fn () => redirect('/login/2'));
            Route::get('login/2', static function () {
                Auth::login(new GenericUser(['id' => 7, 'password' => 'hash']));

                return redirect('/login/3');
            });
            Route::get('login/3', static fn (): string => Auth::check() ? 'user' : 'guest');
        });

        $this->followRedirectChain('/login/1')->assertOk()->assertContent('guest');
    }

    public function test_it_fails_with_the_whole_chain_past_max_hops(): void
    {
        $this->hops();

        $this->followRedirectChain('/hop/1', maxHops: 4)->assertOk()->assertContent('end');

        $this->assertFailsWith(
            'More than 3 redirects: http://localhost/hop/1 302 → http://localhost/hop/2 302 → http://localhost/hop/3 302 → http://localhost/hop/4 302 → http://localhost/hop/5',
            fn () => $this->followRedirectChain('/hop/1', maxHops: 3),
        );
    }

    public function test_the_tests_default_headers_and_cookies_neither_leak_in_nor_change(): void
    {
        Route::get('echo', static fn (Request $request): array => [
            'probe'    => $request->header('X-Probe'),
            'cookies'  => array_keys($request->cookies->all()),
            'agent'    => $request->userAgent(),
            'language' => $request->header('Accept-Language'),
        ]);
        $this->withHeader('X-Probe', 'test')->withCookie('probe', 'test');

        $this->assertSame(
            ['probe' => null, 'cookies' => [], 'agent' => ParsedPage::GOOGLEBOT, 'language' => 'fr'],
            $this->followRedirectChain('/echo', headers: ['Accept-Language' => 'fr'])->json(),
        );
        $this->assertSame('Twitterbot/1.0', $this->followRedirectChain('/echo', userAgent: 'Twitterbot/1.0')->json('agent'));

        $response = $this->get('/echo');
        $this->assertSame('test', $response->json('probe'));
        $this->assertSame(['probe'], $response->json('cookies'));
    }

    public function test_the_tests_redirect_following_and_server_variables_neither_leak_in_nor_change(): void
    {
        $loops = 0;
        Route::get('echo', static fn (Request $request): array => ['auth' => $request->header('Authorization')]);
        Route::get('loop/a', static fn () => redirect('/loop/b'));
        // Guards the test run: Laravel's own follower has no hop limit.
        Route::get('loop/b', static function () use (&$loops) {
            return ++$loops > 50 ? throw new RuntimeException('followed without a limit') : redirect('/loop/a');
        });
        $this->followingRedirects()->withServerVariables(['HTTP_AUTHORIZATION' => 'Bearer test-user']);

        $this->assertNull($this->followRedirectChain('/echo')->json('auth'));
        $this->assertFailsWith('Redirect loop after 2 hops', fn () => $this->followRedirectChain('/loop/a'));

        $this->assertTrue($this->followRedirects);
        $this->assertSame('Bearer test-user', $this->get('/echo')->json('auth'));
    }

    public function test_an_unparseable_location_fails_instead_of_erroring(): void
    {
        Route::get('bad', static fn (): Response => new Response('', 301, ['Location' => 'https:///bad']));

        $this->assertFailsWith('Unparseable URL [https:///bad] from http://localhost/bad', fn () => $this->followRedirectChain('/bad'));
    }

    public function test_a_relative_location_resolves_against_the_host_that_sent_it(): void
    {
        Route::get('relative', static fn (): Response => new Response('', 302, ['Location' => '/landed']));
        Route::get('landed', static fn (Request $request): string => $request->getHost());

        $this->followRedirectChain('http://go.test/relative')->assertContent('go.test');
    }

    public function test_a_crawlable_page_passes_directly_and_after_three_redirects(): void
    {
        $this->fixturePage = static fn (): Page => new Page(title: 'FAQ');
        Route::get('moved/{n}', static fn (string $n) => redirect((int)$n < 3 ? '/moved/' . ((int)$n + 1) : '/faq', 301));

        $this->assertCrawlable('/faq')->assertOk();
        $this->assertCrawlable('/moved/1')->assertOk();
    }

    /** @param Closure(): Response $page */
    #[DataProvider('uncrawlablePages')]
    public function test_assert_crawlable_fails_on(Closure $page, string $message): void
    {
        Route::get('case', $page);

        $this->assertFailsWith($message, fn () => $this->assertCrawlable('/case'));
    }

    /** @return iterable<string, array{Closure(): Response, string}> */
    public static function uncrawlablePages(): iterable
    {
        yield 'a 404' => [static fn (): Response => new Response(self::document(self::HEAD), 404), 'http://localhost/case answered 404'];
        yield 'a noindex meta' => [static fn (): Response => new Response(self::document(self::HEAD . '<meta name="robots" content="noindex">')), 'http://localhost/case is noindex'];
        yield 'a noindex header' => [static fn (): Response => new Response(self::document(self::HEAD), 200, ['X-Robots-Tag' => 'noindex']), 'http://localhost/case is noindex'];
        yield 'a canonical elsewhere' => [static fn (): Response => new Response(self::document('<title>Case</title><link rel="canonical" href="http://localhost/other">')), 'http://localhost/case is canonicalised to http://localhost/other'];
        yield 'no canonical' => [static fn (): Response => new Response(self::document('<title>Case</title>')), 'http://localhost/case has 0 canonical links'];
        yield 'two titles' => [static fn (): Response => new Response(self::document(self::HEAD . '<title>Again</title>')), 'http://localhost/case has 2 <title> elements'];
        // The HTML5 parser closes <head> at the <div>: the canonical lands in <body>, where Google ignores it.
        yield 'a div before the canonical' => [static fn (): Response => new Response(self::document('<title>Case</title><div></div><link rel="canonical" href="http://localhost/case">')), 'in <body>'];
        yield 'an svg og:image' => [static fn (): Response => new Response(self::document(self::HEAD . '<meta property="og:image" content="http://localhost/img/og.SVG?v=2">')), 'has an SVG og:image'];
    }

    public function test_assert_crawlable_fails_past_max_hops(): void
    {
        $this->hops();

        $this->assertFailsWith('More than 3 redirects', fn () => $this->assertCrawlable('/hop/1'));
    }

    public function test_assert_not_indexable_reads_the_header_and_the_meta(): void
    {
        Route::get('tagged', static fn (): Response => new Response('ok', 200, ['X-Robots-Tag' => 'noindex, nofollow']));

        $this->assertNotIndexable($this->get('/tagged'));
        $this->assertNotIndexable($this->visit('/reset-password', new Page(title: 'Reset', robots: Robots::noindex)));

        $this->assertFailsWith('http://localhost/faq is indexable', fn () => $this->assertNotIndexable($this->visit('/faq', new Page(title: 'FAQ'))));
    }

    public function test_assert_noindex_not_disallowed(): void
    {
        $this->fixturePage = static fn (): Page => new Page(title: 'Reset', robots: Robots::noindex);
        Route::middleware('web')->get('admin/reset', $this->renderFixturePage(...));

        $this->assertNoindexNotDisallowed('/reset-password', 'http://go.test/reset-password', 'http://dl.test/reset-password');

        $this->assertFailsWith(
            'http://localhost/admin/reset is disallowed for Googlebot',
            fn () => $this->assertNoindexNotDisallowed('/reset-password', '/admin/reset'),
        );

        $this->fixturePage = static fn (): Page => new Page(title: 'FAQ');
        $this->assertFailsWith('http://localhost/faq is indexable', fn () => $this->assertNoindexNotDisallowed('/faq'));
    }

    public function test_robots_txt_checks_each_hosts_body_and_returns_a_matcher_over_it(): void
    {
        $this->withSitemap(['/faq']);
        $this->assertSame(self::ROBOTS, $this->robotsTxt('localhost')->body);

        foreach (['go.test', 'dl.test'] as $host) {
            $robots = $this->robotsTxt($host);

            $this->assertSame("User-agent: *\nDisallow: /admin/\n", $robots->body);
            $this->assertTrue($robots->allows('Googlebot', '/faq'));
            $this->assertFalse($robots->allows('Googlebot', '/admin/x'));
        }
    }

    /** @param Closure(): mixed $route registers a robots.txt route over the package's */
    #[DataProvider('brokenRobots')]
    public function test_robots_txt_fails_on(Closure $route, string $message): void
    {
        $route();

        $this->assertFailsWith($message, fn () => $this->robotsTxt('localhost'));
    }

    /** @return iterable<string, array{Closure(): mixed, string}> */
    public static function brokenRobots(): iterable
    {
        yield 'a redirect' => [static fn () => Route::get('robots.txt', static fn () => redirect('http://go.test/robots.txt', 301)), 'http://localhost/robots.txt answered 301 → http://go.test/robots.txt'];
        yield 'a 404' => [static fn () => Route::get('robots.txt', static fn () => abort(404)), 'http://localhost/robots.txt answered 404'];
        yield 'html' => [static fn () => Route::get('robots.txt', static fn (): Response => new Response(self::ROBOTS)), 'http://localhost/robots.txt is not text/plain'];
        yield 'another body' => [static fn () => Route::get('robots.txt', static fn (): Response => new Response("User-agent: *\nDisallow: /\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8'])), 'http://localhost/robots.txt is not Site::robotsTxt()'];
    }

    public function test_assert_no_static_shadows(): void
    {
        $fixtures = __DIR__ . '/Fixtures/Assertions';
        $this->app->usePublicPath($fixtures); // no robots.txt, sitemap.xml or indexnow-key.txt at its root

        $this->assertNoStaticShadows();
        $this->assertFailsWith("{$fixtures}/public/robots.txt exists", fn () => $this->assertNoStaticShadows("{$fixtures}/public/robots.txt"));

        $this->app->usePublicPath("{$fixtures}/public");
        $this->assertFailsWith("{$fixtures}/public/robots.txt exists", fn () => $this->assertNoStaticShadows());
    }

    public function test_a_complete_sitemap_passes_even_with_a_page_missing_its_description(): void
    {
        $this->withSitemapPages();

        $this->assertSitemapComplete();
    }

    public function test_hreflang_alternates_may_share_a_title_and_description(): void
    {
        // Uniqueness holds per <html lang>: a cognate (FAQ) is right in every language.
        $this->withLocales(['en', 'fr'], 'en');
        $this->fixturePage = static fn (): Page => new Page(title: 'FAQ', description: 'Questions and answers.');
        $this->withSitemap([new SitemapEntry('/faq', new DateTimeImmutable('2026-09-01T08:00:00+00:00'))]);

        $this->assertSitemapComplete();
    }

    public function test_off_host_assets_are_not_held_to_this_hosts_robots_txt(): void
    {
        // Disallowed here, but cdn.test answers to its own robots.txt.
        $this->withSite(['logo' => 'https://cdn.test/admin/logo.png']);
        Route::get('/', static fn (): Response => new Response(self::document(
            '<title>Home</title><link rel="canonical" href="http://localhost/">'
            . '<link rel="stylesheet" href="https://cdn.test/admin/app.css"><script src="/build/app.js"></script>',
        )));
        $this->withSitemap(['/']);

        $this->assertSitemapComplete();
    }

    public function test_a_text_xml_sitemap_and_an_index_of_files_pass(): void
    {
        $this->withSitemapPages();
        Route::get('sitemap.xml', static fn (): Response => self::sitemapResponse('sitemapindex', ['/pages.xml', '/more.xml']));
        Route::get('pages.xml', static fn (): Response => self::sitemapResponse('urlset', ['/', '/faq'], 'text/xml; charset=UTF-8'));
        Route::get('more.xml', static fn (): Response => self::sitemapResponse('urlset', ['/payment-proof']));

        $this->assertSitemapComplete();
    }

    public function test_a_failing_status_shows_the_exception_behind_it(): void
    {
        $this->withSite(['sitemap' => ['pricing']]);

        $this->assertFailsWith('http://localhost/sitemap.xml answered 500 (expected 200).', fn () => $this->assertSitemapComplete());
        $this->assertFailsWith('InvalidArgumentException: seo.sitemap: [pricing] is not a route name.', fn () => $this->assertSitemapComplete());
    }

    public function test_without_a_sitemap_it_fails(): void
    {
        $this->assertFailsWith('No sitemap: list pages in seo.sitemap or register Seo::sitemapUsing().', fn () => $this->assertSitemapComplete());
    }

    public function test_an_svg_logo_passes_since_google_images_reads_svg(): void
    {
        $this->withSite(['logo' => '/img/logo.svg']);
        $this->withSitemapPages();

        $this->assertSitemapComplete();
    }

    /** @param Closure(self): mixed $arrange */
    #[DataProvider('incompleteSitemaps')]
    public function test_assert_sitemap_complete_fails_on(Closure $arrange, string $message): void
    {
        $this->withSitemapPages();
        $arrange($this);

        $this->assertFailsWith($message, fn () => $this->assertSitemapComplete());
    }

    /** @return iterable<string, array{Closure(self): mixed, string}> */
    public static function incompleteSitemaps(): iterable
    {
        // Valid except for the status or Content-Type under test.
        $faq = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>http://localhost/faq</loc></url></urlset>';

        yield 'a 404' => [static fn () => Route::get('sitemap.xml', static fn (): Response => new Response($faq, 404, ['Content-Type' => 'application/xml'])), 'http://localhost/sitemap.xml answered 404'];
        yield 'html' => [static fn () => Route::get('sitemap.xml', static fn (): Response => new Response($faq, 200, ['Content-Type' => 'text/html; charset=UTF-8'])), 'http://localhost/sitemap.xml is not application/xml or text/xml'];
        yield 'not a sitemap' => [static fn () => Route::get('sitemap.xml', static fn (): Response => new Response('<rss/>', 200, ['Content-Type' => 'application/xml'])), 'http://localhost/sitemap.xml is not a sitemaps.org <urlset> or <sitemapindex>'];
        yield 'a missing index file' => [static fn () => Route::get('sitemap.xml', static fn (): Response => self::sitemapResponse('sitemapindex', ['/gone.xml'])), 'http://localhost/gone.xml answered 404'];
        yield 'an index in an index' => [static function (): void {
            Route::get('sitemap.xml', static fn (): Response => self::sitemapResponse('sitemapindex', ['/nested.xml']));
            Route::get('nested.xml', static fn (): Response => self::sitemapResponse('sitemapindex', []));
        }, 'http://localhost/nested.xml is a <sitemapindex>: an index lists <urlset> files only.'];
        yield 'a loc repeated across index files' => [static function (): void {
            Route::get('sitemap.xml', static fn (): Response => self::sitemapResponse('sitemapindex', ['/pages.xml', '/more.xml']));
            Route::get('pages.xml', static fn (): Response => self::sitemapResponse('urlset', ['/', '/faq']));
            Route::get('more.xml', static fn (): Response => self::sitemapResponse('urlset', ['/faq']));
        }, 'lists these URLs more than once'];
        yield 'no URLs' => [static fn (self $test) => $test->withSitemap([]), 'http://localhost/sitemap.xml lists no URLs'];
        yield 'an off-host loc' => [static fn () => Route::get('sitemap.xml', static fn (): Response => new Response(
            '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>http://go.test/faq</loc></url></urlset>',
            200,
            ['Content-Type' => 'application/xml'],
        )), 'Sitemap loc http://go.test/faq is not on localhost'];
        yield 'a duplicate loc' => [static fn (self $test) => $test->withSitemap(['/faq', '/payment-proof', '/faq']), 'lists these URLs more than once'];
        yield 'a duplicate title' => [static fn (self $test) => $test->withSitemapPages(['/faq' => new Page(title: 'UpFiles', suffixSiteName: false)]), 'Sitemap pages share a title'];
        yield 'a duplicate description' => [static fn (self $test) => $test->withSitemapPages(['/faq' => new Page(title: 'FAQ', description: 'Share files and earn.')]), 'Sitemap pages share a meta description'];
        yield 'a noindex loc' => [static fn (self $test) => $test->withSitemapPages(['/faq' => new Page(title: 'FAQ', robots: Robots::noindex)]), 'http://localhost/faq is noindex'];
        yield 'a disallowed loc' => [static function (self $test): void {
            Route::get('admin/faq', static fn (): Response => new Response(self::document('<title>Admin FAQ</title><link rel="canonical" href="http://localhost/admin/faq">')));
            $test->withSitemap(['/', '/admin/faq']);
        }, 'Sitemap loc http://localhost/admin/faq is disallowed for Googlebot by robots.txt.'];
        yield 'a redirecting loc' => [static function (self $test): void {
            Route::get('old-faq', static fn () => redirect('/faq', 301));
            $test->withSitemap(['/', '/old-faq']);
        }, 'More than 0 redirects: http://localhost/old-faq 301 → http://localhost/faq'];
        yield 'a disallowed stylesheet' => [static fn () => Route::get('/', static fn (): Response => new Response(self::document(
            '<title>Home</title><link rel="canonical" href="http://localhost/"><link rel="stylesheet" href="/admin/app.css">',
        ))), 'stylesheet http://localhost/admin/app.css on http://localhost/ is disallowed for Googlebot'];
        yield 'a disallowed og:image' => [static fn (self $test) => $test->withSite(['disallow' => ['/admin/', '/img/']]), 'og:image http://localhost/img/og-image.png on http://localhost/ is disallowed for Googlebot'];
        yield 'a disallowed logo' => [static fn (self $test) => $test->withSite(['logo' => '/img/logo.png', 'disallow' => ['/admin/', '/img/logo']]), 'logo http://localhost/img/logo.png on http://localhost/ is disallowed for Googlebot'];
    }

    public function test_assert_hreflang_reciprocal_passes_on_path_locales(): void
    {
        $this->withLocales();
        $this->fixturePage = static fn (): Page => new Page(title: 'Listing', paginated: true);

        $this->assertHreflangReciprocal('/faq');
        $this->assertHreflangReciprocal('/ar/faq');
        $this->assertHreflangReciprocal('/fr');
        $this->assertHreflangReciprocal('/fr/payment-proof?page=2');
    }

    public function test_assert_hreflang_reciprocal_fails_when_a_copy_renders_the_accept_language_locale(): void
    {
        // Route middleware inside the closure runs after SetLocale and overrides it.
        $this->withLocalizedRoutes(new Locales(['en', 'fr', 'ar', 'es'], 'en'), function (Router $router): void {
            $router->middleware(NegotiateLocale::class)->get('negotiated', function () {
                $this->seo()->page(title: 'Terms');

                return view('page', ['lang' => $this->app->getLocale()]);
            });
        });

        $this->assertFailsWith(
            'http://localhost/negotiated (Accept-Language: fr) renders <html lang="fr">, not en',
            fn () => $this->assertHreflangReciprocal('/negotiated'),
        );
    }

    public function test_assert_hreflang_reciprocal_fails_when_a_variant_drops_an_alternate(): void
    {
        $this->withLocales();
        // A canonical override drops the fr copy's hreflang block.
        $this->fixturePage = static fn (Request $request): Page => $request->getPathInfo() === '/fr/faq'
            ? new Page(title: 'FAQ', canonical: 'http://localhost/fr/faq')
            : new Page(title: 'FAQ');

        $this->assertFailsWith(
            'http://localhost/fr/faq emits a different hreflang set from /faq',
            fn () => $this->assertHreflangReciprocal('/faq'),
        );
    }

    public function test_assert_hreflang_reciprocal_fails_when_x_default_is_none_of_the_alternates(): void
    {
        Route::get('stray', static fn () => self::document(
            '<title>Stray</title><link rel="canonical" href="http://localhost/stray">'
            . '<link rel="alternate" hreflang="en" href="http://localhost/stray">'
            . '<link rel="alternate" hreflang="x-default" href="http://localhost/elsewhere">',
        ));

        $this->assertFailsWith("/stray's x-default is not one of its alternates", fn () => $this->assertHreflangReciprocal('/stray'));
    }

    public function test_assert_hreflang_reciprocal_fails_without_alternates(): void
    {
        $this->fixturePage = static fn (): Page => new Page(title: 'FAQ');

        $this->assertFailsWith('/faq emits no hreflang alternates', fn () => $this->assertHreflangReciprocal('/faq'));
    }

    /** @param array<string, Page> $pages by path, over the defaults */
    private function withSitemapPages(array $pages = []): void
    {
        $pages += [
            '/'              => new Page(title: 'UpFiles', description: 'Share files and earn.', suffixSiteName: false),
            '/faq'           => new Page(title: 'FAQ', description: 'Questions and answers.'),
            '/payment-proof' => new Page(title: 'Payment proof'),
        ];

        $this->fixturePage = static fn (Request $request): ?Page => $pages[$request->getPathInfo()] ?? null;
        $this->withSitemap(['/', '/faq', '/payment-proof']);
    }

    /** /hop/1 → … → /hop/5, which answers 200. */
    private function hops(): void
    {
        Route::get('hop/{n}', static fn (string $n) => (int)$n < 5 ? redirect('/hop/' . ((int)$n + 1)) : 'end');
    }

    private function assertFailsWith(string $message, Closure $assertion): void
    {
        try {
            $assertion();
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString($message, $e->getMessage());

            return;
        }

        $this->fail("Expected a failure containing [{$message}].");
    }

    private static function document(string $head): string
    {
        return "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\">{$head}</head><body><p>Page</p></body></html>";
    }

    /** @param list<string> $paths on localhost */
    private static function sitemapResponse(string $root, array $paths, string $type = 'application/xml'): Response
    {
        return new Response(self::sitemapXml($root, array_map(static fn (string $path): string => "http://localhost{$path}", $paths)), 200, ['Content-Type' => $type]);
    }
}
