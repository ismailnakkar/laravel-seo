<?php

declare(strict_types=1);

namespace Seo\Tests;

use Illuminate\Http\Request;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Seo\Locales;
use Seo\Page;
use Seo\ParsedPage;
use Seo\Robots;

final class HreflangTest extends TestCase
{
    public function test_every_locale_url_emits_the_identical_set_in_codes_order_with_x_default_last(): void
    {
        $this->withSite();
        $this->withLocales();

        $expected = [
            'en'        => 'http://localhost/faq',
            'fr'        => 'http://localhost/fr/faq',
            'ar'        => 'http://localhost/ar/faq',
            'es'        => 'http://localhost/es/faq',
            'x-default' => 'http://localhost/faq',
        ];

        foreach (['/faq', '/fr/faq', '/ar/faq', '/es/faq', '/fr/faq/', '/faq?lang=fr', 'http://go.test/es/faq?utm_source=x'] as $url) {
            $this->assertSame($expected, $this->alternates($url, new Page(title: 'FAQ')), $url);
        }

        $expected = [
            'en'        => 'http://localhost/',
            'fr'        => 'http://localhost/fr',
            'ar'        => 'http://localhost/ar',
            'es'        => 'http://localhost/es',
            'x-default' => 'http://localhost/',
        ];

        foreach (['/', '/fr', '/ar/', '/es'] as $url) {
            $this->assertSame($expected, $this->alternates($url, new Page(title: 'Home')), $url);
        }
    }

    public function test_an_encoded_locale_prefix_emits_its_plain_spellings_set_and_canonical(): void
    {
        // The router matches the rawurldecoded path, so these reach the fr copy.
        $this->withSite();
        $this->withLocales();

        foreach (['/%66r/faq' => '/faq', '/fr%2Ffaq' => '/faq', '/fr%2ffaq/' => '/faq', '/%66r' => ''] as $url => $path) {
            $page = new Page(title: 'FAQ');
            $expected = [
                'en'        => 'http://localhost' . ($path ?: '/'),
                'fr'        => "http://localhost/fr{$path}",
                'ar'        => "http://localhost/ar{$path}",
                'es'        => "http://localhost/es{$path}",
                'x-default' => 'http://localhost' . ($path ?: '/'),
            ];

            $this->assertSame($expected, $this->alternates($url, $page), $url);
            $this->assertSame("http://localhost/fr{$path}", $this->canonicalOf($url, $page), $url);
        }
    }

    public function test_each_alternate_is_self_canonical_including_the_paginated_combination(): void
    {
        $this->withSite();
        $this->withLocales();

        foreach (['/ar/faq' => new Page(title: 'FAQ'), '/fr' => new Page(title: 'Home'), '/fr/payment-proof?page=2&utm_source=x' => new Page(title: 'Payment proof', paginated: true)] as $url => $page) {
            foreach ($this->alternates($url, $page) as $hreflang => $href) {
                $this->assertSame($href, $this->canonicalOf($href, $page), "{$hreflang} {$href}");
            }
        }

        $this->assertSame([
            'en'        => 'http://localhost/payment-proof?page=2',
            'fr'        => 'http://localhost/fr/payment-proof?page=2',
            'ar'        => 'http://localhost/ar/payment-proof?page=2',
            'es'        => 'http://localhost/es/payment-proof?page=2',
            'x-default' => 'http://localhost/payment-proof?page=2',
        ], $this->alternates('/fr/payment-proof?page=2&utm_source=x', new Page(title: 'Payment proof', paginated: true)));
    }

    public function test_there_are_no_alternates_outside_route_localized_with_an_override_or_on_a_noindex_page(): void
    {
        $site = $this->withSite();
        $this->assertSame([], $this->alternates('/faq', new Page(title: 'FAQ')));
        $this->assertSame([], $site->alternates(Request::create('/fr/faq')));

        $this->withLocales();
        $this->assertSame([], $this->alternates('/fr/faq', new Page(title: 'FAQ', canonical: 'https://short.test/x')));
        $this->assertSame([], $this->alternates('/fr/faq', new Page(title: 'FAQ', robots: Robots::none)));
    }

    public function test_script_and_region_codes_are_path_segments_and_hreflang_values(): void
    {
        // Google documents zh-Hans and zh-Hant: script subtags, optionally with a region.
        $this->withSite();
        $this->withLocales(['en-GB', 'zh-Hant', 'zh-Hant-TW'], 'en-GB');

        $this->assertSame([
            'en-GB'      => 'http://localhost/faq',
            'zh-Hant'    => 'http://localhost/zh-Hant/faq',
            'zh-Hant-TW' => 'http://localhost/zh-Hant-TW/faq',
            'x-default'  => 'http://localhost/faq',
        ], $this->alternates('/zh-Hant-TW/faq', new Page(title: 'FAQ')));
    }

    /** @return iterable<string, array{list<mixed>, string}> */
    public static function badLocales(): iterable
    {
        yield 'a language name' => [['en', 'english'], 'en'];
        yield 'a bare region' => [['en', '-GB'], 'en'];
        yield 'upper-case language' => [['EN'], 'EN'];
        yield 'lower-case region' => [['en', 'en-gb'], 'en'];
        yield 'not a string' => [['en', 2], 'en'];
        yield 'a duplicate' => [['en', 'fr', 'en'], 'en'];
        yield 'no codes' => [[], 'en'];
        yield 'a default not in codes' => [['en', 'fr'], 'de'];
        yield 'a code with a trailing newline' => [['en', "fr\n"], 'en'];
        yield 'lower-case script' => [['zh', 'zh-hant'], 'zh'];
        yield 'a script after the region' => [['zh', 'zh-TW-Hant'], 'zh'];
        yield 'x-default' => [['en', 'x-default'], 'en'];
    }

    /** @param list<mixed> $codes */
    #[DataProvider('badLocales')]
    public function test_malformed_locales_throw(array $codes, string $default): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Locales::');

        new Locales($codes, $default);
    }

    /** @return array<string, string> hreflang => href */
    private function alternates(string $url, ?Page $page = null): array
    {
        return ParsedPage::parse((string)$this->visit($url, $page)->assertOk()->getContent())->alternates;
    }

    private function canonicalOf(string $url, Page $page): ?string
    {
        return ParsedPage::parse((string)$this->visit($url, $page)->getContent())->canonicals[0] ?? null;
    }
}
