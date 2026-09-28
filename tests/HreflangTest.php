<?php

declare(strict_types=1);

namespace Seo\Tests;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Seo\Page;
use Seo\ParsedPage;
use Seo\Robots;

final class HreflangTest extends TestCase
{
    public function test_every_alternate_emits_the_closures_set_in_its_order_with_x_default_last(): void
    {
        $this->withSite();
        $this->withAlternates();

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

    public function test_x_default_is_the_closures_first_alternate(): void
    {
        $this->withSite();
        $this->withAlternates(['fr', 'en', 'zh-Hant']);

        $this->assertSame([
            'fr'        => 'http://localhost/faq',
            'en'        => 'http://localhost/en/faq',
            'zh-Hant'   => 'http://localhost/zh-Hant/faq',
            'x-default' => 'http://localhost/faq',
        ], $this->alternates('/zh-Hant/faq', new Page(title: 'FAQ')));
    }

    public function test_the_canonical_names_the_closures_path(): void
    {
        // The router matches the decoded path: /%66r/faq is the fr copy, which the closure names /fr/faq.
        $this->withSite();
        $this->withAlternates();

        foreach (['/%66r/faq' => '/faq', '/%66r' => ''] as $url => $path) {
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

    public function test_every_alternate_carries_the_page_query_and_is_self_canonical(): void
    {
        $this->withSite();
        $this->withAlternates();
        $page = new Page(title: 'Payment proof', paginated: true);
        $alternates = $this->alternates('/fr/payment-proof?page=2&utm_source=x', $page);

        $this->assertSame([
            'en'        => 'http://localhost/payment-proof?page=2',
            'fr'        => 'http://localhost/fr/payment-proof?page=2',
            'ar'        => 'http://localhost/ar/payment-proof?page=2',
            'es'        => 'http://localhost/es/payment-proof?page=2',
            'x-default' => 'http://localhost/payment-proof?page=2',
        ], $alternates);

        foreach ($alternates as $hreflang => $href) {
            $this->assertSame($href, $this->canonicalOf($href, $page), "{$hreflang} {$href}");
        }
    }

    public function test_there_are_no_alternates_without_a_closure_off_its_routes_with_an_override_or_on_a_noindex_page_or_host(): void
    {
        $this->withSite();
        $this->assertSame([], $this->alternates('/faq', new Page(title: 'FAQ')));

        $this->withAlternates(paths: ['/faq']);
        $this->assertSame([], $this->alternates('/payment-proof', new Page(title: 'Payment proof')));
        $this->assertSame([], $this->alternates('/fr/faq', new Page(title: 'FAQ', canonical: 'https://short.test/x')));
        $this->assertSame([], $this->alternates('/fr/faq', new Page(title: 'FAQ', robots: Robots::none)));
        $this->assertSame([], $this->alternates('http://dl.test/fr/faq', new Page(title: 'FAQ')));
    }

    public function test_a_returned_host_relative_path_stays_on_the_site(): void
    {
        $this->withSite();
        $this->seo()->alternatesUsing(static fn (): array => ['path' => '//evil.test/x', 'alternates' => ['en' => '//evil.test/x', 'fr' => '//evil.test/fr/x']]);
        $page = new Page(title: 'FAQ');

        $this->assertSame([
            'en'        => 'http://localhost/evil.test/x',
            'fr'        => 'http://localhost/evil.test/fr/x',
            'x-default' => 'http://localhost/evil.test/x',
        ], $this->alternates('/faq', $page));
        $this->assertSame('http://localhost/evil.test/x', $this->canonicalOf('/faq', $page));
    }

    /** @return array<string, array{string}> */
    public static function notPaths(): array
    {
        return ['a URL' => ['https://evil.test/x'], 'a query' => ['/faq?lang=fr'], 'a fragment' => ['/faq#top']];
    }

    #[DataProvider('notPaths')]
    public function test_a_returned_url_query_or_fragment_throws(string $value): void
    {
        $this->withSite();
        $this->seo()->alternatesUsing(static fn (): array => ['path' => '/faq', 'alternates' => ['en' => '/faq', 'fr' => $value]]);
        $this->withoutExceptionHandling();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("[{$value}] is not a path");

        $this->visit('/faq', new Page(title: 'FAQ'));
    }

    public function test_a_closure_that_throws_propagates(): void
    {
        $this->withSite();
        $this->seo()->alternatesUsing(static fn (): never => throw new RuntimeException('Resolver down.'));
        $this->withoutExceptionHandling();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Resolver down.');

        $this->visit('/faq', new Page(title: 'FAQ'));
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
