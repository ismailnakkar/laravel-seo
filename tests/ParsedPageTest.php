<?php

declare(strict_types=1);

namespace Seo\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Seo\ParsedPage;

final class ParsedPageTest extends TestCase
{
    public function test_a_well_formed_page_is_read_from_its_head(): void
    {
        $page = ParsedPage::parse(<<<'HTML'
            <!doctype html>
            <html lang="fr">
            <head>
            <meta charset="utf-8">
            <title>
              Café  ·
              UpFiles
            </title>
            <meta name="description" content=" Partage de fichiers. ">
            <meta name="robots" content="max-image-preview:large">
            <link rel="canonical" href="https://upfiles.com/?lang=fr">
            <link rel="alternate" hreflang="en" href="https://upfiles.com/">
            <link rel="alternate" hreflang="fr" href="https://upfiles.com/?lang=fr">
            <link rel="alternate" hreflang="x-default" href="https://upfiles.com/">
            <link rel="alternate" type="application/rss+xml" href="/feed">
            <meta property="og:image" content="https://upfiles.com/img/og-image.png">
            <link rel="stylesheet" href="/build/app.css">
            <link rel="preload" as="style" href="/build/preload.css">
            <script type="module" src="/build/app.js"></script>
            <script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"Organization","name":"UpFiles"},{"@type":"WebSite","name":"UpFiles \u003C/script\u003E"}]}</script>
            </head>
            <body>
            <svg><title>Menu</title></svg>
            <a rel="alternate" hreflang="en" href="/">English</a>
            <script type="APPLICATION/LD+JSON">[{"@type":"FAQPage"},{"@type":"BreadcrumbList"}]</script>
            <script src="/build/late.js"></script>
            <script>var inline = 1;</script>
            <link rel="stylesheet" href="/build/body.css">
            </body>
            </html>
            HTML);

        $this->assertSame('fr', $page->htmlLang);
        $this->assertSame(1, $page->titles);
        $this->assertSame('Café · UpFiles', $page->title);
        $this->assertSame(['https://upfiles.com/?lang=fr'], $page->canonicals);
        $this->assertFalse($page->seoTagsInBody);
        $this->assertSame(['max-image-preview:large'], $page->robots);
        $this->assertSame('Partage de fichiers.', $page->description);
        $this->assertSame([
            'en'        => 'https://upfiles.com/',
            'fr'        => 'https://upfiles.com/?lang=fr',
            'x-default' => 'https://upfiles.com/',
        ], $page->alternates);
        $this->assertSame('https://upfiles.com/img/og-image.png', $page->ogImage);
        $this->assertSame([
            ['@type' => 'Organization', 'name' => 'UpFiles'],
            ['@type' => 'WebSite', 'name' => 'UpFiles </script>'],
            ['@type' => 'FAQPage'],
            ['@type' => 'BreadcrumbList'],
        ], $page->jsonLd);
        $this->assertSame(['/build/app.css', '/build/body.css'], $page->stylesheets);
        $this->assertSame(['/build/app.js', '/build/late.js'], $page->scripts);
    }

    /** @return iterable<string, array{string}> Each closes <head>, as Google parses it. */
    public static function headBreakers(): iterable
    {
        yield 'div' => ['<div></div>'];
        yield 'img' => ['<img src="/pixel.gif">'];
        yield 'stray text' => ['oops'];
        yield 'iframe in noscript (a tag manager snippet)' => ['<noscript><iframe src="https://gtm.test/ns"></iframe></noscript>'];
    }

    #[DataProvider('headBreakers')]
    public function test_an_element_that_does_not_belong_in_head_moves_the_rest_into_body(string $breaker): void
    {
        $page = ParsedPage::parse('<!doctype html><html><head><title>Kept</title>' . $breaker
            . '<link rel="canonical" href="https://x.test/"><meta name="robots" content="noindex">'
            . '<meta name="description" content="Lost"><title>Second</title></head><body></body></html>');

        $this->assertSame(1, $page->titles);
        $this->assertSame('Kept', $page->title);
        $this->assertSame([], $page->canonicals);
        $this->assertSame([], $page->robots);
        $this->assertNull($page->description);
        $this->assertTrue($page->seoTagsInBody);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function bodyTags(): iterable
    {
        yield 'title' => ['<title>T</title>', true];
        yield 'canonical' => ['<link rel="canonical" href="/">', true];
        yield 'canonical among other rel tokens' => ['<link rel="Canonical nofollow" href="/">', true];
        yield 'robots meta' => ['<meta name="ROBOTS" content="noindex">', true];
        yield 'googlebot meta' => ['<meta name="googlebot" content="noindex">', true];
        yield 'hreflang link' => ['<link rel="alternate" hreflang="fr" href="/?lang=fr">', true];
        yield 'an icon title' => ['<svg><title>Menu</title></svg>', false];
        yield 'a language switcher anchor' => ['<a rel="alternate" hreflang="fr" href="/?lang=fr">FR</a>', false];
        yield 'a feed link' => ['<link rel="alternate" type="application/rss+xml" href="/feed">', false];
        yield 'description and og tags' => ['<meta name="description" content="d"><meta property="og:image" content="/i.png">', false];
        yield 'a template' => ['<template><title>T</title><link rel="canonical" href="/"></template>', false];
    }

    #[DataProvider('bodyTags')]
    public function test_seo_tags_in_body_are_flagged(string $markup, bool $flagged): void
    {
        $this->assertSame($flagged, ParsedPage::parse("<html><head><title>T</title></head><body><p>x</p>{$markup}</body></html>")->seoTagsInBody);
    }

    public function test_every_title_and_canonical_in_head_is_counted(): void
    {
        $page = ParsedPage::parse('<head><title>One</title><TITLE>Two</TITLE>'
            . '<link rel="canonical" href=" https://x.test/a "><LINK REL="CANONICAL" href="https://x.test/b"><link rel="canonical"></head>');

        $this->assertSame(2, $page->titles);
        $this->assertSame('One', $page->title);
        $this->assertSame(['https://x.test/a', 'https://x.test/b', ''], $page->canonicals);
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function robotsMetas(): iterable
    {
        yield 'absent' => ['', []];
        yield 'noindex, follow' => ['<meta name="robots" content="noindex, follow">', ['noindex', 'follow']];
        yield 'no space, upper case' => ['<meta name="Robots" content="NOINDEX,NOFOLLOW">', ['noindex', 'nofollow']];
        yield 'none spelled out' => ['<meta name="robots" content="none">', ['noindex', 'nofollow']];
        yield 'googlebot joins robots' => ['<meta name="robots" content="max-image-preview:large"><meta name="googlebot" content="noindex">', ['max-image-preview:large', 'noindex']];
        yield 'blank entries dropped' => ['<meta name="robots" content=" , noindex ,">', ['noindex']];
        yield 'other crawlers ignored' => ['<meta name="bingbot" content="noindex">', []];
    }

    /** @param list<string> $expected */
    #[DataProvider('robotsMetas')]
    public function test_robots_directives_are_those_googlebot_obeys(string $metas, array $expected): void
    {
        $this->assertSame($expected, ParsedPage::parse("<head>{$metas}</head>")->robots);
    }

    /** @return iterable<string, array{list<string>, list<string>}> X-Robots-Tag lines */
    public static function robotsHeaders(): iterable
    {
        yield 'absent' => [[], []];
        yield 'noindex, nofollow' => [['noindex, nofollow'], ['noindex', 'nofollow']];
        yield 'none, upper case' => [['NONE'], ['noindex', 'nofollow']];
        yield 'a preview limit is not none' => [['max-image-preview:none'], ['max-image-preview:none']];
        yield 'another crawler' => [['bingbot: noindex'], []];
        yield 'googlebot' => [['googlebot: noindex'], ['noindex']];
        yield 'a prefix scopes the rest of its line' => [['otherbot: nofollow, noindex'], []];
        yield 'a prefix ends with its line' => [['otherbot: nofollow', 'noindex'], ['noindex']];
        yield 'a second prefix on one line' => [['otherbot: noindex, googlebot: none, max-snippet: -1'], ['noindex', 'nofollow', 'max-snippet: -1']];
    }

    /**
     * @param  list<string>  $lines
     * @param  list<string>  $expected
     */
    #[DataProvider('robotsHeaders')]
    public function test_header_robots_are_the_directives_googlebot_obeys(array $lines, array $expected): void
    {
        $this->assertSame($expected, ParsedPage::headerRobots($lines));
    }

    public function test_a_blank_or_missing_value_is_null(): void
    {
        $blank = ParsedPage::parse('<html><head><meta name="description" content="  "><meta property="og:image" content=""></head></html>');
        $missing = ParsedPage::parse('<p>no head at all</p>');

        foreach ([$blank, $missing] as $page) {
            $this->assertNull($page->description);
            $this->assertNull($page->ogImage);
            $this->assertNull($page->htmlLang);
            $this->assertNull($page->title);
            $this->assertSame(0, $page->titles);
        }
    }

    public function test_the_first_description_and_og_image_win(): void
    {
        $page = ParsedPage::parse('<head><meta name="Description" content="First"><meta name="description" content="Second">'
            . '<meta property="og:image" content="/a.png"><meta property="og:image" content="/b.svg"></head>');

        $this->assertSame('First', $page->description);
        $this->assertSame('/a.png', $page->ogImage);
    }

    public function test_unusable_json_ld_is_skipped(): void
    {
        $page = ParsedPage::parse('<script type="application/ld+json">{"@type":</script>'
            . '<script type="application/ld+json">"a string"</script>'
            . '<script type="application/ld+json"></script>'
            . '<script type="application/ld+json">' . str_repeat('[', 600) . str_repeat(']', 600) . '</script>'
            . '<script type="application/json">{"@type":"NotLd"}</script>'
            . '<script type="application/ld+json">{"@graph":[[{"@type":"Nested"}], 3]}</script>');

        $this->assertSame([['@type' => 'Nested']], $page->jsonLd);
    }

    /** @return iterable<string, array{string}> */
    public static function brokenMarkup(): iterable
    {
        yield 'empty' => [''];
        yield 'not HTML' => ['{"json": true}'];
        yield 'tag soup' => ['<<<>>></p></html><head><title>late</ti'];
        yield 'invalid UTF-8 and NUL' => ["<title>\xff\xfe\x00</title>"];
        yield 'random bytes' => [random_bytes(4096)];
        yield 'unclosed comment' => ['<head><!-- <title>hidden</title>'];
        yield 'frameset' => ['<frameset><frame src="/a"></frameset>'];
        yield 'huge' => ['<head><title>T</title></head><body>' . str_repeat('<div><p>junk &amp; <a href="/x">y</a>', 20_000) . '</body>'];
    }

    #[DataProvider('brokenMarkup')]
    public function test_broken_markup_never_throws_or_warns(string $html): void
    {
        $page = ParsedPage::parse($html);

        $this->assertLessThanOrEqual(1, $page->titles);
        $this->assertIsList($page->jsonLd);
    }

    public function test_bytes_without_a_declared_charset_are_read_as_utf_8(): void
    {
        $this->assertSame('Ünïcödé ツ', ParsedPage::parse('<title>Ünïcödé ツ</title>')->title);
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function urls(): iterable
    {
        yield 'identical' => ['https://x.test/a?b=1', 'https://x.test/a?b=1', true];
        yield 'empty path is the root' => ['https://x.test', 'https://x.test/', true];
        yield 'empty path before a query' => ['https://x.test?lang=fr', 'https://x.test/?lang=fr', true];
        yield 'empty path before a fragment' => ['http://localhost:8000#top', 'http://localhost:8000/#top', true];
        yield 'trailing slash on a path' => ['https://x.test/a', 'https://x.test/a/', false];
        yield 'another query' => ['https://x.test/', 'https://x.test/?page=2', false];
        yield 'another host' => ['https://x.test/', 'https://y.test/', false];
        yield 'another scheme' => ['http://x.test/', 'https://x.test/', false];
    }

    #[DataProvider('urls')]
    public function test_same_url_treats_an_empty_path_as_the_root(string $a, string $b, bool $same): void
    {
        $this->assertSame($same, ParsedPage::sameUrl($a, $b));
        $this->assertSame($same, ParsedPage::sameUrl($b, $a));
    }
}
