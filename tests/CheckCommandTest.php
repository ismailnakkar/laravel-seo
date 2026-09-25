<?php

declare(strict_types=1);

namespace Seo\Tests;

use Closure;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Seo\HostRole;
use Seo\ParsedPage;

final class CheckCommandTest extends TestCase
{
    private const string HOME = 'https://upfiles.com/';

    private const array DISALLOW = ['/member/', '/admin/', '/horizon', '/file/', '/upload/'];

    private const array LOCS = ['https://upfiles.com/', 'https://upfiles.com/login', 'https://upfiles.com/faq'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withUpfiles();
    }

    public function test_a_healthy_site_passes_with_the_exact_report(): void
    {
        $this->fakeLive();

        [$code, $output] = $this->check(['url' => ['https://upfiles.com', 'https://upfilesgo.com/any/path', 'upfiles.download']]);

        $this->assertSame(<<<'TXT'
            seo:check · site https://upfiles.com · crawler rows spoof user agents from this machine's IP and are indicative only

            upfiles.com (index)
              robots.txt ..... PASS 148 bytes, matches the app's body
              sitemap ........ PASS https://upfiles.com/sitemap.xml 200, 3 URLs, all on upfiles.com
              sample ......... PASS 3/3: 200, self-canonical, indexable, no SVG og:image (Googlebot smartphone, no cookies, no redirects)
              descriptions ... PASS 3/3 have a meta description
              home ........... PASS title contains "UpFiles"; WebSite.name "UpFiles"
              crawlers ....... PASS 8 AI search/assistant agents get Chrome's 200
              http ........... PASS http://upfiles.com/ → 301 https://upfiles.com/
              www ............ PASS https://www.upfiles.com/ → 301 https://upfiles.com/
              www ............ PASS https://www.upfiles.com/login → 301 https://upfiles.com/login
            upfilesgo.com (crawl)
              robots.txt ..... PASS 106 bytes, matches the app's body
              http ........... PASS http://upfilesgo.com/ → 301 https://upfilesgo.com/
              www ............ PASS https://www.upfilesgo.com/ → 301 https://upfilesgo.com/
            upfiles.download (noindex)
              robots.txt ..... PASS 106 bytes, matches the app's body
              x-robots-tag ... PASS https://upfiles.download/ 301 carries X-Robots-Tag: noindex, nofollow
              x-robots-tag ... PASS https://upfiles.download/favicon.ico 200 carries X-Robots-Tag: noindex, nofollow
              http ........... PASS http://upfiles.download/ → 301 https://upfiles.download/
              www ............ SKIP www.upfiles.download: could not resolve host

            0 FAIL, 0 WARN, 1 SKIP · exit 0

            TXT, $output);
        $this->assertSame(0, $code);
    }

    public function test_the_url_defaults_to_the_site_and_urls_on_one_host_share_a_block(): void
    {
        $this->fakeLive();

        [, $default] = $this->check();
        [, $twice] = $this->check(['url' => ['https://upfiles.com/faq', 'https://UPFILES.com/login']]);

        $this->assertSame(1, substr_count($default, 'upfiles.com (index)'));
        $this->assertSame($default, $twice);
    }

    /** @return iterable<string, array{Closure(string): (PromiseInterface|Closure), string}> the live robots.txt from the app's body */
    public static function robotsFailures(): iterable
    {
        yield 'lines prepended' => [
            static fn (string $body): PromiseInterface => Http::response("User-agent: Google-Extended\nDisallow: /\n\n{$body}", 200, ['Content-Type' => 'text/plain']),
            'FAIL differs from the app\'s body at line 1: expected "User-agent: *", got "User-agent: Google-Extended"',
        ];
        yield 'origin 404' => [
            static fn (): PromiseInterface => Http::response('<h1>Not found</h1>', 404, ['Content-Type' => 'text/html']),
            'FAIL 404 (expected 200; crawlers read a 4xx as no rules and a 5xx as disallow-all)',
        ];
        // The app's body leads, so no managed block was prepended.
        yield 'lines appended' => [
            static fn (string $body): PromiseInterface => Http::response("{$body}User-agent: GPTBot\nDisallow: /\n", 200, ['Content-Type' => 'text/plain']),
            'FAIL differs from the app\'s body at line 9: expected "", got "User-agent: GPTBot"',
        ];
        yield 'CRLF line endings' => [
            static fn (string $body): PromiseInterface => Http::response(str_replace("\n", "\r\n", $body), 200, ['Content-Type' => 'text/plain']),
            'FAIL differs from the app\'s body at line 1: expected "User-agent: *", got "User-agent: *\r"',
        ];
        yield 'missing final newline' => [
            static fn (string $body): PromiseInterface => Http::response(rtrim($body), 200, ['Content-Type' => 'text/plain']),
            'FAIL differs from the app\'s body at line 9: expected "", got end of file',
        ];
        yield 'served as HTML' => [
            static fn (string $body): PromiseInterface => Http::response($body, 200, ['Content-Type' => 'text/html; charset=UTF-8']),
            'FAIL Content-Type text/html; charset=UTF-8 (expected text/plain)',
        ];
        yield 'unreachable' => [
            static fn (): Closure => Http::failedConnection(),
            'FAIL https://upfiles.com/robots.txt: could not resolve host',
        ];
    }

    /** @param Closure(string): (PromiseInterface|Closure) $robots */
    #[DataProvider('robotsFailures')]
    public function test_a_live_robots_txt_that_is_not_the_apps_fails_with_its_cause(Closure $robots, string $row): void
    {
        $this->fakeLive(['https://upfiles.com/robots.txt' => $robots($this->robotsBody(HostRole::index))]);

        [$code, $output] = $this->check();

        $this->assertRow("  robots.txt ..... {$row}", $output);
        $this->assertSame(1, $code);
    }

    public function test_urls_are_judged_by_the_apps_robots_txt_not_the_live_one(): void
    {
        $this->fakeLive(['https://upfiles.com/robots.txt' => Http::response("User-agent: *\nDisallow: /\n", 200, ['Content-Type' => 'text/plain'])]);

        [, $output] = $this->check();

        $this->assertRow('  robots.txt ..... FAIL differs from the app\'s body at line 2: expected "Disallow: /member/", got "Disallow: /"', $output);
        $this->assertRow('  sitemap ........ PASS https://upfiles.com/sitemap.xml 200, 3 URLs, all on upfiles.com', $output);
    }

    /** @return iterable<string, array{string, string}> */
    public static function sitemapFailures(): iterable
    {
        $xml = static fn (string $loc): string => '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://upfiles.com/</loc></url><url><loc>' . $loc . '</loc></url></urlset>';

        yield 'off-host loc' => [$xml('https://upfilesgo.com/x'), 'application/xml', 200, 'https://upfiles.com/sitemap.xml: https://upfilesgo.com/x is not on upfiles.com'];
        yield 'not found' => ['<h1>Not found</h1>', 'text/html', 404, 'https://upfiles.com/sitemap.xml 404 (expected 200)'];
        yield 'wrong type' => [$xml('https://upfiles.com/x'), 'text/html', 200, 'https://upfiles.com/sitemap.xml Content-Type text/html (expected application/xml or text/xml)'];
        yield 'not xml' => ['<html><body>Sitemap', 'application/xml', 200, 'https://upfiles.com/sitemap.xml is not a sitemaps.org <urlset> or <sitemapindex>'];
        yield 'empty' => ['', 'application/xml', 200, 'https://upfiles.com/sitemap.xml is not a sitemaps.org <urlset> or <sitemapindex>'];
        yield 'no namespace' => ['<urlset><url><loc>https://upfiles.com/</loc></url></urlset>', 'application/xml', 200, 'https://upfiles.com/sitemap.xml is not a sitemaps.org <urlset> or <sitemapindex>'];
        yield 'disallowed loc' => [$xml('https://upfiles.com/member/files'), 'application/xml', 200, 'https://upfiles.com/sitemap.xml: https://upfiles.com/member/files is disallowed for Googlebot by robots.txt'];
    }

    #[DataProvider('sitemapFailures')]
    public function test_a_sitemap_that_is_not_an_index_host_urlset_or_index_fails(string $body, string $type, int $status, string $detail): void
    {
        $this->fakeLive(['https://upfiles.com/sitemap.xml' => Http::response($body, $status, ['Content-Type' => $type])]);

        [$code, $output] = $this->check(['--sample' => '1']);

        $this->assertRow("  sitemap ........ FAIL {$detail}", $output);
        $this->assertSame(1, $code);
    }

    public function test_a_text_xml_sitemap_passes(): void
    {
        $this->fakeLive(['https://upfiles.com/sitemap.xml' => Http::response(self::sitemapXml('urlset', self::LOCS), 200, ['Content-Type' => 'text/xml; charset=UTF-8'])]);

        [, $output] = $this->check();

        $this->assertRow('  sitemap ........ PASS https://upfiles.com/sitemap.xml 200, 3 URLs, all on upfiles.com', $output);
    }

    public function test_an_index_is_followed_into_each_file_and_sampled_from_the_first(): void
    {
        $this->fakeLive([
            'https://upfiles.com/sitemap.xml'   => self::xml(self::sitemapXml('sitemapindex', ['https://upfiles.com/sitemap-1.xml', 'https://upfiles.com/sitemap-2.xml'])),
            'https://upfiles.com/sitemap-1.xml' => self::xml(self::sitemapXml('urlset', self::LOCS)),
            'https://upfiles.com/sitemap-2.xml' => self::xml(self::sitemapXml('urlset', ['https://upfiles.com/p0', 'https://upfiles.com/p1'])),
        ]);

        [$code, $output] = $this->check();

        $this->assertRow('  sitemap ........ PASS https://upfiles.com/sitemap.xml 200, 5 URLs in 2 files, all on upfiles.com', $output);
        $this->assertRow('  sample ......... PASS 3/3: 200, self-canonical, indexable, no SVG og:image (Googlebot smartphone, no cookies, no redirects)', $output);
        Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), '/p0') || str_contains($request->url(), '/p1'));
        $this->assertSame(0, $code);
    }

    /** @return iterable<string, array{array<string, string>, string}> the index's files, the row */
    public static function sitemapIndexFailures(): iterable
    {
        $urlset = self::sitemapXml('urlset', self::LOCS);

        yield 'a file on another host' => [['https://cdn.upfiles.test/sitemap-1.xml' => $urlset], 'https://upfiles.com/sitemap.xml: https://cdn.upfiles.test/sitemap-1.xml is not on upfiles.com'];
        yield 'a file that is an index' => [['https://upfiles.com/sitemap-1.xml' => $urlset, 'https://upfiles.com/sitemap-2.xml' => self::sitemapXml('sitemapindex', [])], 'https://upfiles.com/sitemap-2.xml is not a sitemaps.org <urlset>'];
        yield 'a disallowed loc in a later file' => [['https://upfiles.com/sitemap-1.xml' => $urlset, 'https://upfiles.com/sitemap-2.xml' => self::sitemapXml('urlset', ['https://upfiles.com/admin/x'])], 'https://upfiles.com/sitemap-2.xml: https://upfiles.com/admin/x is disallowed for Googlebot by robots.txt'];
    }

    /** @param array<string, string> $files */
    #[DataProvider('sitemapIndexFailures')]
    public function test_a_sitemap_index_fails_on_its_first_bad_file(array $files, string $detail): void
    {
        $this->fakeLive([
            'https://upfiles.com/sitemap.xml' => self::xml(self::sitemapXml('sitemapindex', array_keys($files))),
            ...array_map(self::xml(...), $files),
        ]);

        [$code, $output] = $this->check();

        $this->assertRow("  sitemap ........ FAIL {$detail}", $output);
        $this->assertSame(1, $code);
    }

    public function test_without_a_sitemap_the_row_and_the_sample_are_skipped(): void
    {
        $this->withUpfiles(['sitemap' => []]);
        $this->fakeLive();

        [$code, $output] = $this->check();

        $this->assertRow('  sitemap ........ SKIP no sitemap configured', $output);
        $this->assertRow('  sample ......... SKIP no sitemap URLs to sample', $output);
        Http::assertNotSent(static fn (Request $request): bool => $request->url() === 'https://upfiles.com/sitemap.xml');
        $this->assertSame(0, $code);
    }

    public function test_without_sitemap_urls_the_sample_is_skipped(): void
    {
        $this->fakeLive(['https://upfiles.com/sitemap.xml' => Http::response('', 404)]);

        [, $output] = $this->check();

        $this->assertRow('  sample ......... SKIP no sitemap URLs to sample', $output);
        $this->assertRow('  descriptions ... SKIP no sitemap URLs to sample', $output);
        $this->assertRow('  www ............ PASS https://www.upfiles.com/ → 301 https://upfiles.com/', $output);
        $this->assertStringNotContainsString('https://www.upfiles.com/login', $output);
    }

    /** @return iterable<string, array{Closure(): (PromiseInterface|Closure), string}> the live /faq */
    public static function unfitSamples(): iterable
    {
        yield 'canonical elsewhere' => [
            static fn (): PromiseInterface => self::html(self::page('https://laravel.upfiles.com/faq')),
            'https://upfiles.com/faq: canonical https://laravel.upfiles.com/faq is not the loc',
        ];
        yield 'two canonicals' => [
            static fn (): PromiseInterface => self::html(self::page('https://upfiles.com/faq', ['<title>' => '<link rel="canonical" href="https://upfiles.com/faq"><title>'])),
            'https://upfiles.com/faq: 2 canonicals in <head> (expected 1)',
        ];
        yield 'a second title left by a migration' => [
            static fn (): PromiseInterface => self::html(self::page('https://upfiles.com/faq', ['<title>' => '<title>FAQ | Old Site</title><title>'])),
            'https://upfiles.com/faq: 2 <title> elements in <head> (expected 1)',
        ];
        yield 'canonical pushed into body' => [
            static fn (): PromiseInterface => self::html(self::page('https://upfiles.com/faq', ['<title>' => '<div></div><title>'])),
            'https://upfiles.com/faq: 0 canonicals in <head> (expected 1)',
        ];
        yield 'robots meta noindex' => [
            static fn (): PromiseInterface => self::html(self::page('https://upfiles.com/faq', ['max-image-preview:large' => 'noindex, follow'])),
            'https://upfiles.com/faq: robots meta noindex',
        ];
        yield 'X-Robots-Tag noindex' => [
            static fn (): PromiseInterface => self::html(self::page('https://upfiles.com/faq'), ['X-Robots-Tag' => 'none']),
            'https://upfiles.com/faq: X-Robots-Tag none',
        ];
        yield 'svg og:image' => [
            static fn (): PromiseInterface => self::html(self::page('https://upfiles.com/faq', ['og-image.png' => 'og-image.SVG'])),
            'https://upfiles.com/faq: og:image https://upfiles.com/img/og-image.SVG is an SVG',
        ];
        yield 'redirect' => [
            static fn (): PromiseInterface => Http::response('', 302, ['Location' => '/login']),
            'https://upfiles.com/faq: 302 (expected 200)',
        ];
        yield 'unreachable' => [
            static fn (): Closure => Http::failedConnection('cURL error 28: Operation timed out after 10001 milliseconds with 0 bytes received (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://upfiles.com/faq'),
            'https://upfiles.com/faq: cURL error 28: Operation timed out after 10001 milliseconds with 0 bytes received',
        ];
    }

    /** @param Closure(): (PromiseInterface|Closure) $faq */
    #[DataProvider('unfitSamples')]
    public function test_a_sample_page_that_is_not_self_canonical_indexable_and_raster_fails(Closure $faq, string $detail): void
    {
        $this->fakeLive(['https://upfiles.com/faq' => $faq()]);

        [$code, $output] = $this->check();

        $this->assertRow("  sample ......... FAIL {$detail}", $output);
        $this->assertSame(1, $code);
    }

    /** @return iterable<string, array{Closure(): array<string, PromiseInterface>, string}> overrides of the live site */
    public static function describedSamples(): iterable
    {
        $bare = static fn (): PromiseInterface => self::html(self::page('https://upfiles.com/login', ['<meta name="description" content="Questions about UpFiles.">' => '']));
        $down = static fn (): PromiseInterface => Http::response('', 500);

        yield 'one without' => [static fn (): array => ['https://upfiles.com/login' => $bare()], 'WARN 1/3 without a meta description: https://upfiles.com/login'];
        yield 'a page that failed is not judged' => [static fn (): array => ['https://upfiles.com/login' => $bare(), 'https://upfiles.com/faq' => $down()], 'WARN 1/2 without a meta description: https://upfiles.com/login'];
        yield 'none answered' => [static fn (): array => array_fill_keys(self::LOCS, $down()), 'SKIP no sample page answered 200'];
    }

    /** @param Closure(): array<string, PromiseInterface> $overrides */
    #[DataProvider('describedSamples')]
    public function test_sample_pages_that_answered_without_a_description_warn(Closure $overrides, string $row): void
    {
        $this->fakeLive($overrides());

        [, $output] = $this->check();

        $this->assertRow("  descriptions ... {$row}", $output);
    }

    public function test_the_sample_takes_the_first_loc_then_evenly_spaced_ones(): void
    {
        $locs = array_map(static fn (int $i): string => "https://upfiles.com/p{$i}", range(0, 9));
        $this->fakeLive(['https://upfiles.com/sitemap.xml' => self::xml(self::sitemapXml('urlset', $locs))]);

        [, $output] = $this->check(['--sample' => '3']);

        $this->assertRow('  sample ......... PASS 3/3: 200, self-canonical, indexable, no SVG og:image (Googlebot smartphone, no cookies, no redirects)', $output);
        $this->assertRow('  www ............ PASS https://www.upfiles.com/p0 → 301 https://upfiles.com/p0', $output);
        $sampled = Http::recorded(static fn (Request $request): bool => str_contains($request->url(), '/p') && $request->header('User-Agent') === [ParsedPage::GOOGLEBOT]);
        $this->assertSame(
            ['https://upfiles.com/p0', 'https://upfiles.com/p3', 'https://upfiles.com/p6'],
            $sampled->map(static fn (array $pair): string => $pair[0]->url())->values()->all(),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function badSampleSizes(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
        yield 'word' => ['all'];
    }

    #[DataProvider('badSampleSizes')]
    public function test_a_sample_size_that_is_not_a_positive_integer_is_refused(string $size): void
    {
        [$code, $output] = $this->check(['--sample' => $size]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('--sample must be a positive integer', $output);
        Http::assertNothingSent();
    }

    public function test_a_link_option_without_a_url_is_refused(): void
    {
        $code = Artisan::call('seo:check --link');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('--link needs a URL.', Artisan::output());
        Http::assertNothingSent();
    }

    /** @return iterable<string, array{array<string, string>, string, int}> replacements in the live home page, the row, the exit code */
    public static function unbrandedHomes(): iterable
    {
        yield 'title without the brand' => [['<title>FAQ · UpFiles</title>' => '<title>exe.io - Monetize your traffic</title>'], 'WARN title "exe.io - Monetize your traffic" does not contain Site name "UpFiles"', 0];
        yield 'no title' => [['<title>FAQ · UpFiles</title>' => ''], 'WARN no <title> in <head>', 0];
        yield 'WebSite named otherwise' => [['"name":"UpFiles"' => '"name":"Up Files"'], 'FAIL no WebSite JSON-LD with name "UpFiles"', 1];
        yield 'Organization only' => [['"@type":"WebSite"' => '"@type":"Organization"'], 'FAIL no WebSite JSON-LD with name "UpFiles"', 1];
    }

    /** @param array<string, string> $replace */
    #[DataProvider('unbrandedHomes')]
    public function test_a_home_page_without_the_brand_in_its_title_warns_and_in_its_json_ld_fails(array $replace, string $row, int $exit): void
    {
        $this->fakeLive([self::HOME => self::html(self::page(self::HOME, $replace))]);

        [$code, $output] = $this->check();

        $this->assertRow("  home ........... {$row}", $output);
        $this->assertSame(1, substr_count($output, '  home ...'));
        $this->assertSame($exit, $code);
    }

    public function test_the_brand_match_ignores_case_and_accepts_a_list_type(): void
    {
        $this->fakeLive([self::HOME => self::html(self::page(self::HOME, ['FAQ · UpFiles' => 'UPFILES home', '"@type":"WebSite"' => '"@type":["WebSite"]']))]);

        [, $output] = $this->check();

        $this->assertRow('  home ........... PASS title contains "UpFiles"; WebSite.name "UpFiles"', $output);
    }

    public function test_a_home_page_warns_while_the_site_name_is_laravels_default(): void
    {
        config(['app.name' => 'Laravel']);
        $this->withUpfiles(['name' => null]);
        $this->fakeLive([self::HOME => self::html(self::page(self::HOME, ['UpFiles' => 'Laravel']))]);

        [$code, $output] = $this->check();

        $this->assertRow("  home ........... WARN Site name is Laravel's default: set APP_NAME or seo.name", $output);
        $this->assertSame(1, substr_count($output, '  home ...'));
        $this->assertSame(0, $code);
    }

    /** @return iterable<string, array{string, Closure(): PromiseInterface, bool, string}> Site::$logo, the live logo, whether the policy disallows /img/, the row */
    public static function logos(): iterable
    {
        $png = static fn (): PromiseInterface => Http::response('png', 200, ['Content-Type' => 'image/png']);

        yield 'png' => ['/img/logo-512.png', $png, false, 'PASS title contains "UpFiles"; WebSite.name "UpFiles"; logo https://upfiles.com/img/logo-512.png 200 image/png'];
        yield 'missing' => ['/img/logo-512.png', static fn (): PromiseInterface => Http::response('gone', 404, ['Content-Type' => 'text/html']), false, 'FAIL logo https://upfiles.com/img/logo-512.png 404 text/html (expected 200 image/png, jpeg, webp, gif, avif, bmp or svg+xml)'];
        // Organization.logo takes any format Google Images supports.
        yield 'svg' => ['/img/logo-512.svg', static fn (): PromiseInterface => Http::response('<svg/>', 200, ['Content-Type' => 'image/svg+xml']), false, 'PASS title contains "UpFiles"; WebSite.name "UpFiles"; logo https://upfiles.com/img/logo-512.svg 200 image/svg+xml'];
        yield 'avif' => ['/img/logo.x', static fn (): PromiseInterface => Http::response('avif', 200, ['Content-Type' => 'image/avif']), false, 'PASS title contains "UpFiles"; WebSite.name "UpFiles"; logo https://upfiles.com/img/logo.x 200 image/avif'];
        yield 'disallowed' => ['/img/logo-512.png', $png, true, 'FAIL logo https://upfiles.com/img/logo-512.png is disallowed for Googlebot by robots.txt'];
        yield 'on a host this robots.txt does not govern' => ['https://cdn.upfiles.test/img/logo-512.png', $png, true, 'PASS title contains "UpFiles"; WebSite.name "UpFiles"; logo https://cdn.upfiles.test/img/logo-512.png 200 image/png'];
    }

    /** @param Closure(): PromiseInterface $response */
    #[DataProvider('logos')]
    public function test_the_logo_must_be_an_image_google_images_reads_and_may_fetch(string $logo, Closure $response, bool $imgDisallowed, string $row): void
    {
        $this->withUpfiles(['logo' => $logo, 'disallow' => $imgDisallowed ? [...self::DISALLOW, '/img/'] : self::DISALLOW]);
        $this->fakeLive([$this->seo()->site()->to($logo) => $response()]);

        [, $output] = $this->check();

        $this->assertRow("  home ........... {$row}", $output);
        $this->assertSame(1, substr_count($output, '  home ...'));
    }

    public function test_a_blank_logo_is_not_checked(): void
    {
        $this->withUpfiles(['logo' => ' ']);
        $this->fakeLive();

        [, $output] = $this->check();

        $this->assertRow('  home ........... PASS title contains "UpFiles"; WebSite.name "UpFiles"', $output);
    }

    /** @return iterable<string, array{string, Closure(): (PromiseInterface|Closure), string}> the refused UA fragment, the refusal */
    public static function refusedAgents(): iterable
    {
        yield 'AI search agent challenged' => [
            'compatible; OAI-SearchBot/',
            static fn (): PromiseInterface => Http::response('Forbidden', 403, ['cf-mitigated' => 'challenge']),
            'FAIL OAI-SearchBot 403 while Chrome gets 200',
        ];
        yield 'AI search agent refused' => [
            'compatible; MistralAI-Index/',
            static fn (): Closure => Http::failedConnection('cURL error 52: Empty reply from server (see https://curl.haxx.se/libcurl/c/libcurl-errors.html)'),
            'FAIL MistralAI-Index cURL error 52: Empty reply from server while Chrome gets 200',
        ];
    }

    /** @param Closure(): (PromiseInterface|Closure) $refusal */
    #[DataProvider('refusedAgents')]
    public function test_an_edge_refusing_an_ai_search_agent_fails(string $agent, Closure $refusal, string $row): void
    {
        $this->fakeLive([self::HOME => static fn (Request $request, array $options): PromiseInterface => str_contains($request->header('User-Agent')[0] ?? '', $agent)
            ? value($refusal(), $request, $options)
            : self::html(self::page(self::HOME))]);

        [$code, $output] = $this->check();

        $this->assertRow("  crawlers ....... {$row}", $output);
        $this->assertSame(1, substr_count($output, '  crawlers ...'));
        $this->assertSame(1, $code);
    }

    public function test_only_ai_search_agents_and_claude_user_are_probed(): void
    {
        $this->fakeLive();

        $this->check();

        $probes = Http::recorded(static fn (Request $request): bool => str_starts_with($request->header('User-Agent')[0] ?? '', 'Mozilla/5.0 (compatible; '));
        $this->assertSame(
            ['OAI-SearchBot', 'Claude-SearchBot', 'PerplexityBot', 'DuckAssistBot', 'Amzn-SearchBot', 'meta-webindexer', 'MistralAI-Index', 'Claude-User'],
            $probes->map(static fn (array $pair): string => substr($pair[0]->header('User-Agent')[0], 25, -5))->values()->all(),
        );
    }

    public function test_a_refused_chrome_baseline_skips_the_crawler_probes(): void
    {
        $this->fakeLive([self::HOME => static fn (Request $request): PromiseInterface => $request->header('User-Agent') === [ParsedPage::CHROME]
            ? Http::response('Forbidden', 403, ['cf-mitigated' => 'challenge'])
            : self::html(self::page(self::HOME))]);

        [$code, $output] = $this->check();

        $this->assertRow('  home ........... FAIL https://upfiles.com/ 403 (expected 200)', $output);
        $this->assertRow('  crawlers ....... SKIP baseline Chrome got 403', $output);
        // The Chrome fetch and the Googlebot sample: no probe.
        $this->assertCount(2, Http::recorded(static fn (Request $request): bool => $request->url() === self::HOME));
        $this->assertSame(1, $code);
    }

    public function test_www_and_http_hosts_that_do_not_redirect_fail(): void
    {
        $this->fakeLive([
            'https://www.upfiles.com/*'  => Http::response('', 404),
            'http://upfilesgo.com/'      => Http::response('', 404),
            'https://www.upfilesgo.com/' => Http::response('', 404),
        ]);

        [$code, $output] = $this->check(['url' => ['https://upfiles.com', 'https://upfilesgo.com']]);

        $this->assertRow('  www ............ FAIL https://www.upfiles.com/ → 404 (expected one 301/308 to https://upfiles.com/)', $output);
        $this->assertRow('  www ............ FAIL https://www.upfiles.com/login → 404 (expected one 301/308 to https://upfiles.com/login)', $output);
        $this->assertRow('  http ........... FAIL http://upfilesgo.com/ → 404 (expected one 301/308 to https://upfilesgo.com/ or https://upfiles.com/)', $output);
        $this->assertRow('  www ............ FAIL https://www.upfilesgo.com/ → 404 (expected one 301/308 to https://upfilesgo.com/ or https://upfiles.com/)', $output);
        $this->assertStringEndsWith("\n\n4 FAIL, 0 WARN, 0 SKIP · exit 1\n", $output);
        $this->assertSame(1, $code);
    }

    /** @return iterable<string, array{string, string}> the www fetch error, the row */
    public static function wwwErrors(): iterable
    {
        yield 'refused' => ["cURL error 7: Failed to connect to www.upfilesgo.com port 443 after 3 ms: Couldn't connect to server", "SKIP www.upfilesgo.com: cURL error 7: Failed to connect to www.upfilesgo.com port 443 after 3 ms: Couldn't connect to server"];
        yield 'certificate without www' => ['cURL error 60: SSL: no alternative certificate subject name matches target host name', 'FAIL https://www.upfilesgo.com/ → cURL error 60: SSL: no alternative certificate subject name matches target host name (expected one 301/308 to https://upfilesgo.com/ or https://upfiles.com/)'];
    }

    #[DataProvider('wwwErrors')]
    public function test_only_a_www_host_that_cannot_be_reached_is_skipped(string $error, string $row): void
    {
        $this->fakeLive(['https://www.upfilesgo.com/' => Http::failedConnection("{$error} (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://www.upfilesgo.com/")]);

        [, $output] = $this->check(['url' => ['https://upfilesgo.com']]);

        $this->assertRow("  www ............ {$row}", $output);
    }

    /** @return iterable<string, array{Closure(): (PromiseInterface|Closure), string}> the live http://upfilesgo.com/ */
    public static function httpRedirects(): iterable
    {
        yield 'straight to the site' => [static fn (): PromiseInterface => Http::response('', 308, ['Location' => 'https://upfiles.com']), 'PASS http://upfilesgo.com/ → 308 https://upfiles.com'];
        yield 'temporary' => [static fn (): PromiseInterface => Http::response('', 302, ['Location' => 'https://upfilesgo.com/']), 'FAIL http://upfilesgo.com/ → 302 https://upfilesgo.com/ (expected one 301/308 to https://upfilesgo.com/ or https://upfiles.com/)'];
        yield 'to another path' => [static fn (): PromiseInterface => Http::response('', 301, ['Location' => 'https://upfiles.com/login']), 'FAIL http://upfilesgo.com/ → 301 https://upfiles.com/login (expected one 301/308 to https://upfilesgo.com/ or https://upfiles.com/)'];
        yield 'unreachable' => [
            static fn (): Closure => Http::failedConnection("cURL error 7: Failed to connect to upfilesgo.com port 80 after 3 ms: Couldn't connect to server (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for http://upfilesgo.com/"),
            "FAIL http://upfilesgo.com/ → cURL error 7: Failed to connect to upfilesgo.com port 80 after 3 ms: Couldn't connect to server (expected one 301/308 to https://upfilesgo.com/ or https://upfiles.com/)",
        ];
    }

    /** @param Closure(): (PromiseInterface|Closure) $response */
    #[DataProvider('httpRedirects')]
    public function test_http_must_redirect_permanently_in_one_hop_to_https_or_the_site(Closure $response, string $row): void
    {
        $this->fakeLive(['http://upfilesgo.com/' => $response()]);

        [, $output] = $this->check(['url' => ['https://upfilesgo.com']]);

        $this->assertRow("  http ........... {$row}", $output);
    }

    public function test_a_relative_location_is_resolved_against_the_url_it_came_from(): void
    {
        $this->fakeLive([
            'https://www.upfilesgo.com/' => Http::response('', 301, ['Location' => '//upfilesgo.com/']),
            'http://upfilesgo.com/'      => Http::response('', 301, ['Location' => '/']),
        ]);

        [, $output] = $this->check(['url' => ['https://upfilesgo.com']]);

        $this->assertRow('  www ............ PASS https://www.upfilesgo.com/ → 301 //upfilesgo.com/', $output);
        // Still http: a path keeps the scheme it came from.
        $this->assertRow('  http ........... FAIL http://upfilesgo.com/ → 301 / (expected one 301/308 to https://upfilesgo.com/ or https://upfiles.com/)', $output);
    }

    public function test_www_hosts_are_not_probed_for_www(): void
    {
        $this->fakeLive([
            'https://www.upfiles.com/robots.txt' => Http::response($this->robotsBody(HostRole::crawl), 200, ['Content-Type' => 'text/plain']),
            'http://www.upfiles.com/'            => Http::response('', 301, ['Location' => 'https://upfiles.com/']),
        ]);

        [$code, $output] = $this->check(['url' => ['https://www.upfiles.com']]);

        $this->assertStringEndsWith(<<<'TXT'

            www.upfiles.com (crawl)
              robots.txt ..... PASS 106 bytes, matches the app's body
              http ........... PASS http://www.upfiles.com/ → 301 https://upfiles.com/

            0 FAIL, 0 WARN, 0 SKIP · exit 0

            TXT, $output);
        $this->assertSame(0, $code);
    }

    /** @return iterable<string, array{Closure(): PromiseInterface, Closure(): PromiseInterface, list<string>}> the live root and favicon */
    public static function noindexHosts(): iterable
    {
        yield 'header missing on both' => [
            static fn (): PromiseInterface => Http::response('', 301, ['Location' => 'https://upfiles.com/']),
            static fn (): PromiseInterface => Http::response('icon', 200, ['Content-Type' => 'image/x-icon']),
            [
                '  x-robots-tag ... FAIL https://upfiles.download/ 301 has no X-Robots-Tag',
                '  x-robots-tag ... WARN https://upfiles.download/favicon.ico 200 has no X-Robots-Tag (served by nginx: see README Hosts)',
            ],
        ];
        yield 'header without noindex' => [
            static fn (): PromiseInterface => Http::response('', 200, ['X-Robots-Tag' => 'max-image-preview:large']),
            static fn (): PromiseInterface => Http::response('', 404),
            [
                '  x-robots-tag ... FAIL https://upfiles.download/ 200 has X-Robots-Tag: max-image-preview:large without noindex',
                '  x-robots-tag ... SKIP https://upfiles.download/favicon.ico 404',
            ],
        ];
        yield 'googlebot scoped' => [
            static fn (): PromiseInterface => Http::response('', 200, ['X-Robots-Tag' => 'googlebot: noindex']),
            static fn (): PromiseInterface => Http::response('', 404),
            [
                '  x-robots-tag ... PASS https://upfiles.download/ 200 carries X-Robots-Tag: googlebot: noindex',
                '  x-robots-tag ... SKIP https://upfiles.download/favicon.ico 404',
            ],
        ];
    }

    /**
     * @param  Closure(): PromiseInterface  $root
     * @param  Closure(): PromiseInterface  $favicon
     * @param  list<string>  $rows
     */
    #[DataProvider('noindexHosts')]
    public function test_a_noindex_host_needs_the_header_on_php_and_static_responses(Closure $root, Closure $favicon, array $rows): void
    {
        $this->fakeLive(['https://upfiles.download/' => $root(), 'https://upfiles.download/favicon.ico' => $favicon()]);

        [, $output] = $this->check(['url' => ['https://upfiles.download']]);

        $this->assertStringContainsString("upfiles.download (noindex)\n  robots.txt ..... PASS 106 bytes, matches the app's body\n" . implode("\n", $rows) . "\n  http ...", $output);
    }

    public function test_links_are_followed_cookieless_as_googlebot_and_judged_by_hops(): void
    {
        // An app-wide jar must not reach the probes either.
        Http::globalOptions(['cookies' => CookieJar::fromArray(['laravel_session' => 'x'], 'upfilesgo.com')]);
        $this->fakeLive([
            'https://upfiles.com/2BONFN'                   => Http::response('', 302, ['Location' => 'https://upfilesgo.com/2BONFN?auth_token=a2d2']),
            'https://upfilesgo.com/2BONFN?auth_token=a2d2' => Http::response('', 302, ['Location' => 'https://upfilesgo.com/2BONFN']),
            'https://upfilesgo.com/2BONFN'                 => Http::response('', 302, ['Location' => 'https://upfiles.com/2BONFN']),
            'https://upfilesgo.com/X'                      => Http::response('', 302, ['Location' => '/X?bounced=1']),
            'https://upfilesgo.com/X?bounced=1'            => self::html('ok'),
            'https://upfilesgo.com/hop*'                   => static fn (Request $request): PromiseInterface => (int)substr($request->url(), 25) < 12
                ? Http::response('', 301, ['Location' => 'hop' . ((int)substr($request->url(), 25) + 1)])
                : self::html('ok'),
            'https://upfilesgo.com/gone' => Http::response('', 404),
        ]);

        [$code, $output] = $this->check(['--link' => [
            'https://upfiles.com/2BONFN', 'https://upfilesgo.com/X', 'https://upfilesgo.com/hop9', 'https://upfilesgo.com/hop8', 'https://upfilesgo.com/hop0', 'https://upfilesgo.com/gone',
        ]]);

        $this->assertStringEndsWith(<<<'TXT'
              www ............ PASS https://www.upfiles.com/login → 301 https://upfiles.com/login
            link https://upfiles.com/2BONFN
              cookieless ..... FAIL loop after 3 hops: https://upfiles.com/2BONFN → https://upfilesgo.com/2BONFN?auth_token=a2d2 → https://upfilesgo.com/2BONFN → https://upfiles.com/2BONFN
            link https://upfilesgo.com/X
              cookieless ..... PASS 200 after 1 hop: https://upfilesgo.com/X → https://upfilesgo.com/X?bounced=1
            link https://upfilesgo.com/hop9
              cookieless ..... PASS 200 after 3 hops: https://upfilesgo.com/hop9 → https://upfilesgo.com/hop10 → https://upfilesgo.com/hop11 → https://upfilesgo.com/hop12
            link https://upfilesgo.com/hop8
              cookieless ..... WARN 200 after 4 hops (Google advises 3 or fewer): https://upfilesgo.com/hop8 → https://upfilesgo.com/hop9 → https://upfilesgo.com/hop10 → https://upfilesgo.com/hop11 → https://upfilesgo.com/hop12
            link https://upfilesgo.com/hop0
              cookieless ..... FAIL 301 after 10 hops: https://upfilesgo.com/hop0 → https://upfilesgo.com/hop1 → https://upfilesgo.com/hop2 → https://upfilesgo.com/hop3 → https://upfilesgo.com/hop4 → https://upfilesgo.com/hop5 → https://upfilesgo.com/hop6 → https://upfilesgo.com/hop7 → https://upfilesgo.com/hop8 → https://upfilesgo.com/hop9 → https://upfilesgo.com/hop10
            link https://upfilesgo.com/gone
              cookieless ..... FAIL 404 after 0 hops: https://upfilesgo.com/gone

            3 FAIL, 1 WARN, 0 SKIP · exit 1

            TXT, $output);
        $this->assertSame(1, $code);

        $hops = Http::recorded(static fn (Request $request): bool => str_contains($request->url(), 'upfilesgo.com/'));
        $this->assertNotEmpty($hops);
        $hops->each(function (array $pair): void {
            $this->assertSame([ParsedPage::GOOGLEBOT], $pair[0]->header('User-Agent'));
            $this->assertFalse($pair[0]->hasHeader('Cookie'));
        });
    }

    public function test_a_link_that_cannot_be_followed_fails_and_the_run_goes_on(): void
    {
        $this->fakeLive([
            'https://upfilesgo.com/bad' => Http::response('', 301, ['Location' => 'https:///bad']),
            // What Laravel throws for a 4xx/5xx whose body broke off.
            'https://upfilesgo.com/cut' => static fn (): never => throw new RequestException(new Response(new Psr7Response(503, [], 'down'))),
            'https://upfilesgo.com/X'   => self::html('ok'),
        ]);

        [$code, $output] = $this->check(['--link' => ['https://upfilesgo.com/bad', 'https://upfilesgo.com/cut', 'http:///x', 'https://upfilesgo.com/X']]);

        $this->assertStringEndsWith(<<<'TXT'
            link https://upfilesgo.com/bad
              cookieless ..... FAIL 301 to unparseable Location https:///bad after 0 hops: https://upfilesgo.com/bad
            link https://upfilesgo.com/cut
              cookieless ..... FAIL 503 after 0 hops: https://upfilesgo.com/cut
            link http:///x
              cookieless ..... FAIL Unable to parse URI: http:///x after 0 hops: http:///x
            link https://upfilesgo.com/X
              cookieless ..... PASS 200 after 0 hops: https://upfilesgo.com/X

            3 FAIL, 0 WARN, 0 SKIP · exit 1

            TXT, $output);
        $this->assertSame(1, $code);
    }

    /**
     * The upfiles Site.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function withUpfiles(array $overrides = []): void
    {
        $this->withSite([
            'url'           => 'https://upfiles.com',
            'disallow'      => self::DISALLOW,
            'noindex_hosts' => ['upfiles.download'],
            'sitemap'       => ['/', '/login', '/faq'],
            ...$overrides,
        ]);
    }

    /**
     * A healthy deploy's answer to every URL the command fetches; an unfaked URL throws.
     *
     * @param  array<string, PromiseInterface|Closure>  $overrides
     */
    private function fakeLive(array $overrides = []): void
    {
        $redirect = static fn (string $to): PromiseInterface => Http::response('', 301, ['Location' => $to]);
        $robots = fn (HostRole $role): PromiseInterface => Http::response($this->robotsBody($role), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
        $noindex = ['X-Robots-Tag' => 'noindex, nofollow'];

        Http::fake($overrides + [
            'https://upfiles.com/robots.txt'       => $robots(HostRole::index),
            'https://upfilesgo.com/robots.txt'     => $robots(HostRole::crawl),
            'https://upfiles.download/robots.txt'  => $robots(HostRole::noindex),
            'https://upfiles.com/sitemap.xml'      => self::xml(self::sitemapXml('urlset', self::LOCS)),
            'http://upfiles.com/'                  => $redirect('https://upfiles.com/'),
            'http://upfilesgo.com/'                => $redirect('https://upfilesgo.com/'),
            'http://upfiles.download/'             => $redirect('https://upfiles.download/'),
            'https://www.upfiles.com/*'            => static fn (Request $request): PromiseInterface => $redirect(str_replace('://www.', '://', $request->url())),
            'https://www.upfilesgo.com/'           => $redirect('https://upfilesgo.com/'),
            'https://www.upfiles.download/'        => Http::failedConnection(),
            'https://upfiles.download/'            => Http::response('', 301, ['Location' => 'https://upfiles.com/', ...$noindex]),
            'https://upfiles.download/favicon.ico' => Http::response('icon', 200, ['Content-Type' => 'image/x-icon', ...$noindex]),
            'https://upfiles.com/*'                => static fn (Request $request): PromiseInterface => self::html(self::page($request->url())),
        ]);
    }

    private function robotsBody(HostRole $role): string
    {
        $site = $this->seo()->site();

        return $site->robotsTxt($role, $this->seo()->sitemapUrl($site));
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{int, string} the exit code and the report
     */
    private function check(array $parameters = []): array
    {
        $code = Artisan::call('seo:check', $parameters);

        return [$code, Artisan::output()];
    }

    private function assertRow(string $row, string $output): void
    {
        $this->assertStringContainsString("\n{$row}\n", $output);
    }

    /**
     * A page with the head the package renders; $replace breaks parts of it.
     *
     * @param  array<string, string>  $replace
     */
    private static function page(string $canonical, array $replace = []): string
    {
        $head = strtr(<<<HTML
            <title>FAQ · UpFiles</title>
            <meta name="description" content="Questions about UpFiles.">
            <meta name="robots" content="max-image-preview:large">
            <link rel="canonical" href="{$canonical}">
            <meta property="og:image" content="https://upfiles.com/img/og-image.png">
            <script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"WebSite","name":"UpFiles","url":"https://upfiles.com/"}]}</script>
            HTML, $replace);

        return "<!doctype html><html lang=\"en\"><head>{$head}</head><body><h1>UpFiles</h1></body></html>";
    }

    /** @param array<string, string|list<string>> $headers */
    private static function html(string $body, array $headers = []): PromiseInterface
    {
        return Http::response($body, 200, ['Content-Type' => 'text/html; charset=UTF-8', ...$headers]);
    }

    private static function xml(string $body): PromiseInterface
    {
        return Http::response($body, 200, ['Content-Type' => 'application/xml']);
    }
}
