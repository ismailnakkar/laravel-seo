<?php

declare(strict_types=1);

namespace Seo\Tests;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Seo\Page;

final class AlternatesSeamTest extends TestCase
{
    public function test_the_resolver_answers_with_its_paths(): void
    {
        $this->seo()->alternatesUsing(static fn (): array => ['path' => '/faq', 'alternates' => ['en' => '/faq', 'fr' => '/fr/faq']]);

        $this->assertSame(
            ['path' => '/faq', 'alternates' => ['en' => '/faq', 'fr' => '/fr/faq']],
            $this->seo()->alternatesFor($this->faq(), '/faq'),
        );
    }

    public function test_a_protocol_relative_path_stays_on_the_site(): void
    {
        $this->seo()->alternatesUsing(static fn (): array => ['path' => '//evil.test/x', 'alternates' => ['en' => '//evil.test/x']]);

        $this->assertSame(
            ['path' => '/evil.test/x', 'alternates' => ['en' => '/evil.test/x']],
            $this->seo()->alternatesFor($this->faq(), '/faq'),
        );
    }

    public function test_a_scheme_inside_a_path_is_still_a_path(): void
    {
        $this->seo()->alternatesUsing(static fn (): array => ['path' => '/x/https://y.test', 'alternates' => ['en' => '/x/https://y.test']]);

        $this->assertSame(
            ['path' => '/x/https://y.test', 'alternates' => ['en' => '/x/https://y.test']],
            $this->seo()->alternatesFor($this->faq(), '/x/https://y.test'),
        );
    }

    /** @return array<string, array{string}> */
    public static function notPaths(): array
    {
        return ['absolute' => ['https://evil.test/x'], 'query' => ['/faq?lang=fr'], 'fragment' => ['/faq#top']];
    }

    #[DataProvider('notPaths')]
    public function test_a_returned_value_that_is_not_a_path_throws(string $value): void
    {
        $this->seo()->alternatesUsing(static fn (): array => ['path' => '/faq', 'alternates' => ['en' => '/faq', 'fr' => $value]]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($value);

        $this->seo()->alternatesFor($this->faq(), '/faq');
    }

    public function test_an_answer_without_alternates_throws(): void
    {
        $this->seo()->alternatesUsing(static fn (): array => ['path' => '/faq', 'alternates' => []]);

        $this->expectException(LogicException::class);

        $this->seo()->alternatesFor($this->faq(), '/faq');
    }

    public function test_no_matched_route_never_calls_the_resolver(): void
    {
        $calls = 0;
        $this->seo()->alternatesUsing(static function () use (&$calls): array {
            $calls++;

            return ['path' => '/faq', 'alternates' => ['en' => '/faq']];
        });

        $this->assertNull($this->seo()->alternatesFor(null, '/faq'));
        $this->assertSame(0, $calls);
    }

    public function test_site_alternates_calls_the_resolver_once(): void
    {
        $calls = 0;
        $this->seo()->alternatesUsing(static function () use (&$calls): array {
            $calls++;

            return ['path' => '/faq', 'alternates' => ['en' => '/faq', 'fr' => '/fr/faq']];
        });
        $route = $this->faq();
        $request = Request::create('/faq?page=2');
        $request->setRouteResolver(static fn (): Route => $route);

        $this->assertSame(
            ['en' => 'http://localhost/faq?page=2', 'fr' => 'http://localhost/fr/faq?page=2', 'x-default' => 'http://localhost/faq?page=2'],
            $this->withSite()->alternates($request, new Page(title: 'FAQ', paginated: true)),
        );
        $this->assertSame(1, $calls);
    }

    public function test_without_a_resolver_nothing_is_localized(): void
    {
        $this->assertNull($this->seo()->alternatesFor($this->faq(), '/faq'));
    }

    private function faq(): Route
    {
        return Router::get('faq', static fn (): string => 'FAQ');
    }
}
