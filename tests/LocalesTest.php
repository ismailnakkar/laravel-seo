<?php

declare(strict_types=1);

namespace Seo\Tests;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Seo\Locales;

final class LocalesTest extends TestCase
{
    /** @return iterable<string, array{list<string>, string, ?string}> codes (default first), Accept-Language, expected */
    public static function browsers(): iterable
    {
        yield 'exact' => [['en', 'fr'], 'fr', 'fr'];
        yield 'a region falls back to its language' => [['en', 'fr'], 'fr-CA,en;q=0.5', 'fr'];
        yield 'the first language wins over a later exact match' => [['en', 'fr'], 'fr-CA,en;q=0.9', 'fr'];
        yield 'a configured region, any case' => [['en-GB', 'fr'], 'en-gb', 'en-GB'];
        yield 'a script code' => [['en', 'zh-Hant'], 'zh-Hant', 'zh-Hant'];
        yield 'a script code by its language' => [['en', 'zh-Hant'], 'zh-TW', 'zh-Hant'];
        yield 'the bare code before a regional one' => [['en', 'pt-BR', 'pt'], 'pt', 'pt'];
        yield 'a regional request, the bare code configured' => [['en', 'pt-BR', 'pt'], 'pt-PT', 'pt'];
        yield 'nothing matches' => [['en', 'fr'], 'de-DE,de', null];
        yield 'a wildcard' => [['en', 'fr'], '*', null];
        yield 'no header' => [['en', 'fr'], '', null];
    }

    /** @param list<string> $codes */
    #[DataProvider('browsers')]
    public function test_the_browsers_first_language_that_matches_wins(array $codes, string $header, ?string $expected): void
    {
        $request = Request::create('/', server: ['HTTP_ACCEPT_LANGUAGE' => $header]);

        $this->assertSame($expected, new Locales($codes, $codes[0])->preferredBy($request));
    }

    public function test_a_malformed_header_never_throws(): void
    {
        $locales = new Locales(['en', 'fr'], 'en');

        foreach ([';;;,,=', 'q=0', "\x00fr", str_repeat('fr-', 5000)] as $header) {
            $request = Request::create('/', server: ['HTTP_ACCEPT_LANGUAGE' => $header]);

            $this->assertContains($locales->preferredBy($request), [null, 'en', 'fr']);
        }
    }
}
