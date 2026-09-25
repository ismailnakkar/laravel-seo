<?php

declare(strict_types=1);

namespace Seo;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\ParentNode;
use Dom\XMLDocument;
use DOMException;
use GuzzleHttp\Psr7\Exception\MalformedUriException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use ValueError;

/**
 * A page's search markup as Googlebot reads it, plus sitemap, URL and header helpers, for SeoAssertions and seo:check.
 *
 * @internal
 */
final readonly class ParsedPage
{
    public const string GOOGLEBOT = 'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

    public const string CHROME = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';

    public const array SITEMAP_TYPES = ['application/xml', 'text/xml'];

    /** Googlebot obeys both names, and pages are read as Googlebot. */
    private const string ROBOTS = 'meta[name="robots" i], meta[name="googlebot" i]';

    /** `name: value` directives, so their name is never a crawler's. */
    private const array VALUED = ['max-snippet', 'max-image-preview', 'max-video-preview', 'unavailable_after'];

    private const string CANONICAL = 'link[rel~="canonical" i]';

    private const string HREFLANG = 'link[rel~="alternate" i][hreflang]';

    private const string SITEMAP_NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    /** Read only in <head>. An svg <title> is an icon's name, not the page's. */
    private const string HEAD_ONLY = 'title:not(svg *), ' . self::CANONICAL . ', ' . self::HREFLANG . ', ' . self::ROBOTS;

    /**
     * @param  list<string>  $canonicals  hrefs in <head>, trimmed; '' for a missing href
     * @param  list<string>  $robots  lower-cased, from the robots metas in <head>; `none` as noindex, nofollow
     * @param  array<string, string>  $alternates  hreflang => href, in <head>
     * @param  list<array<string, mixed>>  $jsonLd  every node on the page: lists and @graph flattened, bad JSON skipped
     * @param  list<string>  $stylesheets  hrefs, anywhere on the page
     * @param  list<string>  $scripts  srcs, anywhere on the page
     */
    private function __construct(
        public ?string $htmlLang,
        public int $titles,           // in <head>
        public ?string $title,        // the first in <head>, whitespace collapsed as a browser tab shows it
        public array $canonicals,
        public bool $seoTagsInBody,
        public array $robots,
        public ?string $description,  // the first in <head>, trimmed; null when absent or blank
        public array $alternates,
        public ?string $ogImage,      // the first in <head>, trimmed; null when absent or blank
        public array $jsonLd,
        public array $stylesheets,
        public array $scripts,
    ) {}

    /**
     * HTML5 tree building: anything that does not belong in <head> (a <div>, an <img>, stray text) closes it and
     * every later tag lands in <body>; Google stops reading <head> there too. Never throws on markup.
     */
    public static function parse(string $html): self
    {
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $head = $document->head;
        $titles = self::all($head, 'title');

        $robots = [];

        foreach (self::all($head, self::ROBOTS) as $meta) {
            foreach (explode(',', strtolower((string)$meta->getAttribute('content'))) as $directive) {
                array_push($robots, ...self::directive($directive));
            }
        }

        $alternates = [];

        foreach (self::all($head, self::HREFLANG) as $link) {
            $alternates[(string)$link->getAttribute('hreflang')] = self::attribute($link, 'href');
        }

        $jsonLd = [];

        foreach (self::all($document, 'script[type="application/ld+json" i]') as $script) {
            array_push($jsonLd, ...self::nodes(json_decode($script->textContent, true)));
        }

        return new self(
            htmlLang: $document->documentElement?->getAttribute('lang'),
            titles: count($titles),
            title: $titles === [] ? null : trim((string)preg_replace('/[\t\n\f\r ]+/', ' ', $titles[0]->textContent)),
            canonicals: array_map(static fn (Element $link): string => self::attribute($link, 'href'), self::all($head, self::CANONICAL)),
            seoTagsInBody: $document->body?->querySelector(self::HEAD_ONLY) !== null,
            robots: $robots,
            description: self::filled($head?->querySelector('meta[name="description" i]'), 'content'),
            alternates: $alternates,
            ogImage: self::filled($head?->querySelector('meta[property="og:image" i]'), 'content'),
            jsonLd: $jsonLd,
            stylesheets: array_map(static fn (Element $link): string => self::attribute($link, 'href'), self::all($document, 'link[rel~="stylesheet" i][href]')),
            scripts: array_map(static fn (Element $script): string => self::attribute($script, 'src'), self::all($document, 'script[src]')),
        );
    }

    /**
     * The X-Robots-Tag directives Googlebot obeys. A `crawler:` prefix scopes the rest of its line, so only unscoped
     * and `googlebot:` directives count.
     *
     * @param  list<string>  $lines  never joined: a scope ends with its line
     * @return list<string>
     */
    public static function headerRobots(array $lines): array
    {
        $robots = [];

        foreach ($lines as $line) {
            $scope = null;

            foreach (explode(',', strtolower($line)) as $directive) {
                if (preg_match('/^\s*([a-z0-9_-]+)\s*:(.*)$/s', $directive, $prefix) === 1 && ! in_array($prefix[1], self::VALUED, true)) {
                    [, $scope, $directive] = $prefix;
                }

                if ($scope === null || $scope === 'googlebot') {
                    array_push($robots, ...self::directive($directive));
                }
            }
        }

        return $robots;
    }

    /** @return array{'urlset'|'sitemapindex', list<string>}|null the root and its trimmed locs; null when neither */
    public static function sitemap(string $xml): ?array
    {
        try {
            $root = XMLDocument::createFromString($xml, LIBXML_NONET | LIBXML_NOERROR)->documentElement;
        } catch (DOMException|ValueError) { // ValueError: an empty body
            return null;
        }

        if (! in_array($root?->localName, ['urlset', 'sitemapindex'], true) || $root->namespaceURI !== self::SITEMAP_NS) {
            return null;
        }

        return [$root->localName, array_map(static fn (Element $loc): string => trim($loc->textContent), iterator_to_array($root->getElementsByTagNameNS(self::SITEMAP_NS, 'loc'), false))];
    }

    /** An empty path counts as '/': https://x.test and https://x.test/ are one URL. */
    public static function sameUrl(string $a, string $b): bool
    {
        return self::withRootPath($a) === self::withRootPath($b);
    }

    /** As a browser resolves a Location or an href; null when either cannot be parsed. */
    public static function resolve(string $base, string $reference): ?string
    {
        try {
            return (string)UriResolver::resolve(new Uri($base), new Uri($reference));
        } catch (MalformedUriException) {
            return null;
        }
    }

    /** Path and query, as RobotsMatcher::allows() takes them. */
    public static function robotsPath(string $url): string
    {
        $parts = parse_url($url);

        return (($parts['path'] ?? '') ?: '/') . (isset($parts['query']) ? "?{$parts['query']}" : '');
    }

    public static function mediaType(?string $contentType): string
    {
        return strtolower(trim(explode(';', (string)$contentType)[0]));
    }

    /** Link previews do not render SVG. */
    public function svgOgImage(): bool
    {
        return str_ends_with(strtolower((string)parse_url((string)$this->ogImage, PHP_URL_PATH)), '.svg');
    }

    private static function withRootPath(string $url): string
    {
        return (string)preg_replace('~^([a-z][a-z0-9+.-]*://[^/?#]*)(?=[?#]|\z)~i', '$1/', $url);
    }

    /** @return list<string> `none` expanded; max-image-preview:none stays whole */
    private static function directive(string $directive): array
    {
        return match ($directive = trim($directive)) {
            ''      => [],
            'none'  => ['noindex', 'nofollow'],
            default => [$directive],
        };
    }

    /** @return list<Element> */
    private static function all(?ParentNode $scope, string $selectors): array
    {
        return $scope === null ? [] : iterator_to_array($scope->querySelectorAll($selectors), false);
    }

    private static function attribute(Element $element, string $name): string
    {
        return trim((string)$element->getAttribute($name));
    }

    private static function filled(?Element $element, string $name): ?string
    {
        $value = $element === null ? '' : self::attribute($element, $name);

        return $value === '' ? null : $value;
    }

    /** @return list<array<string, mixed>> */
    private static function nodes(mixed $value): array
    {
        return match (true) {
            ! is_array($value)                 => [],
            array_is_list($value)              => array_merge(...array_map(self::nodes(...), $value)),
            is_array($value['@graph'] ?? null) => self::nodes($value['@graph']),
            default                            => [$value],
        };
    }
}
