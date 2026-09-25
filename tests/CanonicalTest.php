<?php

declare(strict_types=1);

namespace Seo\Tests;

use Illuminate\Http\Request;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Seo\Page;
use Seo\Site;

final class CanonicalTest extends TestCase
{
    public function test_index_php_a_trailing_slash_and_the_root_normalise(): void
    {
        $this->withSite();
        $viaFrontController = ['SCRIPT_FILENAME' => '/app/public/index.php', 'SCRIPT_NAME' => '/index.php'];

        $this->assertSame('http://localhost/terms', $this->canonical('/index.php/terms', server: $viaFrontController));
        $this->assertSame('http://localhost/terms', $this->canonical('/terms/'));
        $this->assertSame('http://localhost/', $this->canonical('/'));
        $this->assertSame('http://localhost/', $this->canonical('http://localhost'));
    }

    public function test_tracking_and_auth_parameters_are_dropped(): void
    {
        $this->withSite();

        $this->assertSame('http://localhost/terms', $this->canonical(
            '/terms?utm_source=x&utm_medium=y&auth_token=t&bounced=1&signature=s&fbclid=f&ref=r',
        ));
    }

    public function test_a_page_number_is_dropped_when_the_page_is_not_paginated(): void
    {
        $this->withSite();

        $this->assertSame('http://localhost/terms', $this->canonical('/terms?page=2', new Page(title: 'Terms')));
    }

    /** @return iterable<string, array{string, string}> */
    public static function pageNumbers(): iterable
    {
        yield 'kept' => ['page=2', 'http://localhost/payment-proof?page=2'];
        yield 'leading zero' => ['page=02', 'http://localhost/payment-proof'];
        yield 'plus sign' => ['page=%2B2', 'http://localhost/payment-proof?page=2'];
        yield 'first page' => ['page=1', 'http://localhost/payment-proof'];
        yield 'zero' => ['page=0', 'http://localhost/payment-proof'];
        yield 'not a number' => ['page=x', 'http://localhost/payment-proof'];
        yield 'negative' => ['page=-1', 'http://localhost/payment-proof'];
        yield 'fraction' => ['page=2.5', 'http://localhost/payment-proof'];
        yield 'array' => ['page[]=2', 'http://localhost/payment-proof'];
        yield 'keyed array' => ['page[a]=2', 'http://localhost/payment-proof'];
        yield 'empty' => ['page=', 'http://localhost/payment-proof'];
    }

    #[DataProvider('pageNumbers')]
    public function test_a_paginated_page_keeps_only_a_real_page_number_above_one(string $query, string $canonical): void
    {
        $this->withSite();

        $this->assertSame($canonical, $this->canonical("/payment-proof?{$query}", new Page(title: 'Payment proof', paginated: true)));
    }

    public function test_the_request_host_is_ignored(): void
    {
        $this->withSite();

        $this->assertSame('http://localhost/terms', $this->canonical('https://go.test/terms'));
        $this->assertSame('http://localhost/terms', $this->canonical('http://www.localhost:8080/terms'));
    }

    public function test_an_override_passes_through_unchanged(): void
    {
        $this->withSite();

        $this->assertSame('https://short.test/abc?x=1', $this->canonical('/faq?page=2', new Page(title: 'A', canonical: 'https://short.test/abc?x=1', paginated: true)));
    }

    public function test_a_root_relative_override_lands_on_the_site_url_not_the_request_host(): void
    {
        // As route('x', $parameters, absolute: false) spells it.
        $this->withSite();

        $this->assertSame('http://localhost/abc?id=7', $this->canonical('https://go.test/faq', new Page(canonical: '/abc?id=7')));
        $this->visit('https://go.test/faq', new Page(canonical: '/abc?id=7'))
            ->assertSee('<link rel="canonical" href="http://localhost/abc?id=7">', false)
            ->assertSee('<meta property="og:url" content="http://localhost/abc?id=7">', false);
    }

    /** @return iterable<string, array{string}> */
    public static function relativeCanonicals(): iterable
    {
        yield 'no scheme' => ['short.test/abc'];
        yield 'relative path' => ['abc'];
        yield 'relative host' => ['//short.test/abc'];
        yield 'other scheme' => ['ftp://short.test/abc'];
    }

    #[DataProvider('relativeCanonicals')]
    public function test_a_canonical_that_is_neither_an_absolute_http_url_nor_a_root_relative_path_throws_at_the_call(string $canonical): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Page::$canonical');

        $this->seo()->page(title: 'A', canonical: $canonical);
    }

    /** @return iterable<string, array{string}> */
    public static function badOrigins(): iterable
    {
        yield 'path' => ['https://upfiles.com/app'];
        yield 'query' => ['https://upfiles.com?a=1'];
        yield 'empty query' => ['https://upfiles.com/?'];
        yield 'fragment' => ['https://upfiles.com#top'];
        yield 'userinfo' => ['https://user:pass@upfiles.com'];
        yield 'trailing dot' => ['https://upfiles.com./'];
        yield 'no scheme' => ['upfiles.com'];
        yield 'no host' => ['https:///x'];
        yield 'ftp' => ['ftp://upfiles.com'];
        yield 'empty' => [''];
    }

    #[DataProvider('badOrigins')]
    public function test_a_site_url_that_is_not_an_http_origin_throws(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Site::$url');

        new Site(name: 'UpFiles', url: $url);
    }

    public function test_a_site_url_normalises_to_an_origin_without_a_trailing_slash(): void
    {
        $site = $this->withSite(['url' => 'HTTPS://UpFiles.com:8443/']);

        $this->assertSame('https://upfiles.com:8443', $site->url);
        $this->assertSame('upfiles.com', $site->host());
    }

    public function test_to_builds_every_link_on_the_site_origin(): void
    {
        $site = $this->withSite();

        $this->assertSame('http://localhost/', $site->to(''));
        $this->assertSame('http://localhost/', $site->to('/'));
        $this->assertSame('http://localhost/faq', $site->to('faq'));
        $this->assertSame('http://localhost/terms?a=1', $site->to('/terms?a=1'));
        $this->assertSame('https://cdn.test/x.png', $site->to('https://cdn.test/x.png'));
    }

    public function test_to_treats_a_leading_scheme_or_a_network_path_as_another_url(): void
    {
        // Not "contains ://": a path whose query carries a URL is still a path.
        $site = $this->withSite();

        $this->assertSame('http://localhost/out?to=https://x.test/', $site->to('/out?to=https://x.test/'));
        // RFC 3986 §4.2: `//host/path` names another host, on the Site's scheme.
        $this->assertSame('http://evil.test/x', $site->to('//evil.test/x'));
        $this->assertSame('https://cdn.test/og.png', $this->withSite(['url' => 'https://upfiles.test'])->to('//cdn.test/og.png'));
    }

    public function test_is_home_matches_the_index_host_root_with_any_query(): void
    {
        $site = $this->withSite();

        $this->assertTrue($site->isHome('http://localhost'));
        $this->assertTrue($site->isHome('http://localhost/'));
        $this->assertTrue($site->isHome('http://LOCALHOST/?utm_source=x'));
        $this->assertFalse($site->isHome('http://localhost/faq'));
        $this->assertFalse($site->isHome('http://go.test/'));
        $this->assertFalse($site->isHome('/'));
    }

    /** @param array<string, string> $server */
    private function canonical(string $url, ?Page $page = null, array $server = []): string
    {
        return $this->seo()->site()->canonical(Request::create($url, server: $server), $page);
    }
}
