<?php

declare(strict_types=1);

namespace Seo\Testing;

use GuzzleHttp\Psr7\Uri;
use Illuminate\Testing\TestResponse;
use Illuminate\Testing\TestResponseAssert;
use Seo\Console\InstallCommand;
use Seo\ParsedPage;
use Seo\Seo;
use Seo\Site;
use Symfony\Component\HttpFoundation\Response;

/**
 * Crawlability assertions for a Laravel or Testbench test case. Every fetch is a fresh visitor through the kernel:
 * the session is flushed and guards forgotten first, so a test's actingAs() does not survive a helper.
 */
trait SeoAssertions
{
    /**
     * Follows redirects through the kernel as a crawler does, cookieless, and returns the last response. The test's
     * default headers, cookies, server variables and followingRedirects() neither leak in nor change. Fails with the
     * whole chain on a loop or after $maxHops.
     *
     * @param  array<string, string>  $headers  extra request headers
     * @return TestResponse<Response>
     */
    protected function followRedirectChain(string $url, int $maxHops = 10, string $userAgent = ParsedPage::GOOGLEBOT, array $headers = []): TestResponse
    {
        return $this->seoChain($url, $maxHops, $userAgent, $headers)[0];
    }

    /**
     * Cookieless, Googlebot smartphone, at most $maxHops (Google: "ideally no more than 3"). The final response must be:
     *   - 200 and not noindex (meta or header);
     *   - after an HTML5 parse: exactly one <title> and one canonical in <head>, the canonical equal to the final URL;
     *     no title, canonical, robots meta or hreflang link in <body>;
     *   - og:image not .svg.
     *
     * @return TestResponse<Response>
     */
    protected function assertCrawlable(string $url, int $maxHops = 3): TestResponse
    {
        [$response, $url] = $this->seoChain($url, $maxHops, ParsedPage::GOOGLEBOT);
        $page = ParsedPage::parse((string)$response->getContent());

        $this->seoAssertOk($response, "{$url} answered {$response->getStatusCode()} (expected 200).");
        $this->assertFalse(self::seoNoindex($response), "{$url} is noindex (X-Robots-Tag or robots meta).");
        // Before the counts: it explains a tag missing from <head>.
        $this->assertFalse($page->seoTagsInBody, "{$url} has a title, canonical, robots meta or hreflang link in <body>: an element that does not belong in <head> closed it early.");
        $this->assertSame(1, $page->titles, "{$url} has {$page->titles} <title> elements in <head> (expected exactly one).");
        $this->assertCount(1, $page->canonicals, "{$url} has " . count($page->canonicals) . ' canonical links in <head> (expected exactly one).');
        $this->assertTrue(ParsedPage::sameUrl($page->canonicals[0], $url), "{$url} is canonicalised to {$page->canonicals[0]}, not to itself.");
        $this->assertFalse($page->svgOgImage(), "{$url} has an SVG og:image ({$page->ogImage}): link previews do not render SVG.");

        return $response;
    }

    /**
     * X-Robots-Tag or robots meta contains noindex.
     *
     * @param  TestResponse<Response>  $response
     */
    protected function assertNotIndexable(TestResponse $response): void
    {
        $this->assertTrue(self::seoNoindex($response), ($response->baseRequest?->fullUrl() ?? 'The response') . ' is indexable: neither X-Robots-Tag nor a robots meta says noindex.');
    }

    /**
     * Each URL, fetched cookieless without following redirects, is noindex AND allowed for Googlebot by its host's
     * robots.txt: a noindex behind a Disallow is never read.
     */
    protected function assertNoindexNotDisallowed(string ...$urls): void
    {
        foreach ($urls as $url) {
            $url = $this->seoAbsolute($url);

            $this->assertNotIndexable($this->seoFetch($url, ParsedPage::GOOGLEBOT));
            $this->assertTrue(
                $this->robotsTxt((string)parse_url($url, PHP_URL_HOST))->allows('Googlebot', ParsedPage::robotsPath($url)),
                "{$url} is disallowed for Googlebot: its noindex is never read, and links can still get it indexed.",
            );
        }
    }

    /**
     * http://{host}/robots.txt, fetched cookieless, answers 200 text/plain and is exactly Site::robotsTxt() for the
     * host's role. Returns a matcher over it.
     */
    protected function robotsTxt(string $host): RobotsMatcher
    {
        $url = "http://{$host}/robots.txt";
        $response = $this->seoFetch($url, ParsedPage::GOOGLEBOT);
        $location = $response->headers->get('Location');
        $body = (string)$response->getContent();
        $site = $this->seoSite();

        $this->seoAssertOk($response, "{$url} answered {$response->getStatusCode()}" . ($location === null ? '' : " → {$location}") . ' (expected 200).');
        $this->assertStringStartsWith('text/plain', (string)$response->headers->get('Content-Type'), "{$url} is not text/plain.");
        $this->assertSame($site->robotsTxt($site->roleOf($host), $this->app->make(Seo::class)->sitemapUrl($site)), $body, "{$url} is not Site::robotsTxt() for its host's role.");

        return new RobotsMatcher($body);
    }

    /** public/robots.txt, public/sitemap.xml, public/indexnow-key.txt and the extra (full) paths must not exist. */
    protected function assertNoStaticShadows(string ...$extraPaths): void
    {
        foreach (InstallCommand::SHADOWS as $file) {
            $extraPaths[] = $this->app->publicPath($file);
        }

        foreach ($extraPaths as $path) {
            $this->assertFileDoesNotExist($path, "{$path} exists: the web server serves it and the package's route never runs.");
        }
    }

    /**
     * Structural only: never asserts content an admin edits. Fails when Seo::sitemapUrl() is null. The index host's
     * /sitemap.xml, and each file a <sitemapindex> lists, answers 200 application/xml or text/xml, and:
     *   - every loc is on Site::host(), with no duplicates, and passes assertCrawlable($loc, 0);
     *   - every loc is allowed for Googlebot by the index host's robots.txt;
     *   - titles are unique within one <html lang>; non-empty descriptions likewise;
     *   - on the home loc: every same-host stylesheet, script src, og:image and logo path is allowed for Googlebot.
     */
    protected function assertSitemapComplete(): void
    {
        $site = $this->seoSite();

        $this->assertNotNull($this->app->make(Seo::class)->sitemapUrl($site), 'No sitemap: list pages in seo.sitemap or register Seo::sitemapUsing().');

        $url = $site->to('/sitemap.xml');
        [$root, $locs] = $this->seoSitemap($url);

        if ($root === 'sitemapindex') {
            $files = $locs;
            $locs = [];

            foreach ($files as $file) {
                [$root, $fileLocs] = $this->seoSitemap($file);
                $this->assertSame('urlset', $root, "{$file} is a <sitemapindex>: an index lists <urlset> files only.");
                array_push($locs, ...$fileLocs);
            }
        }

        $this->assertNotSame([], $locs, "{$url} lists no URLs.");

        $robots = $this->robotsTxt($site->host());

        foreach ($locs as $loc) {
            $this->assertSame($site->host(), strtolower((string)parse_url($loc, PHP_URL_HOST)), "Sitemap loc {$loc} is not on {$site->host()}.");
            $this->assertTrue($robots->allows('Googlebot', ParsedPage::robotsPath($loc)), "Sitemap loc {$loc} is disallowed for Googlebot by robots.txt.");
        }

        $this->assertSame([], self::seoRepeated($locs), "{$url} lists these URLs more than once.");

        $pages = [];

        foreach ($locs as $loc) {
            $pages[$loc] = ParsedPage::parse((string)$this->assertCrawlable($loc, 0)->getContent());
        }

        // Per <html lang>: hreflang alternates may rightly share a cognate or brand-only title.
        $languages = [];

        foreach ($pages as $loc => $page) {
            $languages[strtolower((string)$page->htmlLang)][$loc] = $page;
        }

        foreach ($languages as $group) {
            $this->assertSame([], self::seoRepeated(array_map(static fn (ParsedPage $page): string => (string)$page->title, $group)), 'Sitemap pages share a title: each needs its own.');
            $this->assertSame([], self::seoRepeated(array_filter(array_map(static fn (ParsedPage $page): ?string => $page->description, $group), is_string(...))), 'Sitemap pages share a meta description: each needs its own, or none.');
        }

        $logo = filled($site->logo) ? $site->to($site->logo) : null;

        foreach (array_filter($pages, $site->isHome(...), ARRAY_FILTER_USE_KEY) as $loc => $page) {
            $assets = [
                ...array_map(static fn (string $href): array => ['stylesheet', $href], $page->stylesheets),
                ...array_map(static fn (string $src): array => ['script', $src], $page->scripts),
                ['og:image', $page->ogImage],
                ['logo', $logo],
            ];

            foreach ($assets as [$kind, $href]) {
                $asset = $href === null ? null : $this->seoResolve($loc, $href);

                // Another host's asset answers to that host's robots.txt.
                if ($asset === null || strtolower((string)parse_url($asset, PHP_URL_HOST)) !== $site->host()) {
                    continue;
                }

                $this->assertTrue($robots->allows('Googlebot', ParsedPage::robotsPath($asset)), "{$kind} {$asset} on {$loc} is disallowed for Googlebot.");
            }
        }
    }

    /**
     * Fetches $url's hreflang hrefs cookieless, each with an Accept-Language naming a DIFFERENT language, so a URL
     * that negotiates its language fails. Each must answer 200, be self-canonical, emit the same alternate set and
     * render an <html lang> whose primary subtag is its hreflang's. x-default is required and takes the language of
     * the code sharing its href.
     */
    protected function assertHreflangReciprocal(string $url): void
    {
        $alternates = ParsedPage::parse((string)$this->seoFetch($url, ParsedPage::GOOGLEBOT)->getContent())->alternates;
        $this->assertNotSame([], $alternates, "{$url} emits no hreflang alternates.");

        $codes = array_keys(array_diff_key($alternates, ['x-default' => true]));
        $default = array_find($codes, static fn (string $code): bool => isset($alternates['x-default']) && ParsedPage::sameUrl($alternates[$code], $alternates['x-default']));
        $this->assertNotNull($default, "{$url}'s x-default is not one of its alternates.");

        foreach ($alternates as $hreflang => $href) {
            $language = self::seoLanguage((string)($hreflang === 'x-default' ? $default : $hreflang));
            $other = array_find($codes, static fn (string $code): bool => self::seoLanguage($code) !== $language);
            $asked = $other === null ? '' : " (Accept-Language: {$other})";
            $response = $this->seoFetch($href, ParsedPage::GOOGLEBOT, $other === null ? [] : ['Accept-Language' => $other]);
            $page = ParsedPage::parse((string)$response->getContent());

            $this->seoAssertOk($response, "{$href}{$asked} answered {$response->getStatusCode()} (expected 200).");
            $this->assertTrue(
                count($page->canonicals) === 1 && ParsedPage::sameUrl($page->canonicals[0], $href),
                "{$href} is not self-canonical: " . (implode(', ', $page->canonicals) ?: 'no canonical') . '.',
            );
            $this->assertEquals($alternates, $page->alternates, "{$href} emits a different hreflang set from {$url}.");
            $this->assertSame($language, self::seoLanguage((string)$page->htmlLang), "{$href}{$asked} renders <html lang=\"{$page->htmlLang}\">, not {$language}: its language must come from the URL alone.");
        }
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{TestResponse<Response>, string} the last response and its URL
     */
    private function seoChain(string $url, int $maxHops, string $userAgent, array $headers = []): array
    {
        $url = $this->seoAbsolute($url);
        $seen = [];
        $chain = '';

        for ($hop = 0; ; $hop++) {
            if (isset($seen[$url])) {
                $this->fail("Redirect loop after {$hop} hops: {$chain}{$url}");
            }

            if ($hop > $maxHops) {
                $this->fail("More than {$maxHops} redirects: {$chain}{$url}");
            }

            $seen[$url] = true;
            $response = $this->seoFetch($url, $userAgent, $headers);
            $location = $response->isRedirection() ? $response->headers->get('Location') : null;

            if ($location === null) {
                return [$response, $url];
            }

            $chain .= "{$url} {$response->getStatusCode()} → ";
            $url = $this->seoResolve($url, $location);
        }
    }

    /**
     * One cookieless kernel GET carrying only these headers. Identity is reset first: the kernel shares one session
     * store and guard across requests, so a login on one hop would survive into the next and hide a loop.
     *
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function seoFetch(string $url, string $userAgent, array $headers = []): TestResponse
    {
        $this->app['session']->driver()->flush();
        $this->app['auth']->forgetGuards();

        $server = ['HTTP_USER_AGENT' => $userAgent];

        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtr(strtoupper($name), '-', '_')] = $value;
        }

        // call() applies both to every request; a followed redirect would hide the hop being judged.
        [$follow, $serverVariables] = [$this->followRedirects, $this->serverVariables];
        [$this->followRedirects, $this->serverVariables] = [false, []];

        try {
            return $this->call('GET', $url, [], [], [], $server);
        } finally {
            [$this->followRedirects, $this->serverVariables] = [$follow, $serverVariables];
        }
    }

    /**
     * assertStatus()'s wrapper: a 500 also prints the exception behind it.
     *
     * @param  TestResponse<Response>  $response
     */
    private function seoAssertOk(TestResponse $response, string $message): void
    {
        TestResponseAssert::withResponse($response)->assertSame(200, $response->getStatusCode(), $message);
    }

    /** A path resolves the way the test's own get() would resolve it. */
    private function seoAbsolute(string $url): string
    {
        return Uri::isAbsolute(new Uri($url)) ? $url : $this->prepareUrlForRequest($url);
    }

    private function seoSite(): Site
    {
        return $this->app->make(Seo::class)->site();
    }

    /** @return array{'urlset'|'sitemapindex', list<string>} */
    private function seoSitemap(string $url): array
    {
        $response = $this->seoFetch($url, ParsedPage::GOOGLEBOT);
        $sitemap = ParsedPage::sitemap((string)$response->getContent());

        $this->seoAssertOk($response, "{$url} answered {$response->getStatusCode()} (expected 200).");
        $this->assertContains(ParsedPage::mediaType($response->headers->get('Content-Type')), ParsedPage::SITEMAP_TYPES, "{$url} is not application/xml or text/xml.");
        $this->assertNotNull($sitemap, "{$url} is not a sitemaps.org <urlset> or <sitemapindex>.");

        return $sitemap;
    }

    private function seoResolve(string $base, string $reference): string
    {
        return ParsedPage::resolve($base, $reference) ?? $this->fail("Unparseable URL [{$reference}] from {$base}.");
    }

    /** @param TestResponse<Response> $response */
    private static function seoNoindex(TestResponse $response): bool
    {
        return in_array('noindex', [
            ...ParsedPage::headerRobots($response->headers->all('X-Robots-Tag')),
            ...ParsedPage::parse((string)$response->getContent())->robots,
        ], true);
    }

    /**
     * @param  array<array-key, string>  $values
     * @return array<array-key, string> every occurrence of the ones that occur more than once, keys kept
     */
    private static function seoRepeated(array $values): array
    {
        $counts = array_count_values($values);

        return array_filter($values, static fn (string $value): bool => $counts[$value] > 1);
    }

    /** Primary subtag: `fr` for fr-CA. */
    private static function seoLanguage(string $tag): string
    {
        return strtolower(explode('-', $tag)[0]);
    }
}
