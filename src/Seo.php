<?php

declare(strict_types=1);

namespace Seo;

use Closure;
use Generator;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use Throwable;

/** Builds the Site and holds each request's Page. Not final so apps can mock it. */
class Seo
{
    /** sitemaps.org per-file limit. */
    private const int MAX_URLS = 50_000;

    private ?Closure $siteResolver = null;

    private ?Closure $sitemapResolver = null;

    private ?Closure $alternatesResolver = null;

    public function __construct(private readonly Router $router) {}

    /**
     * seo.* overrides, e.g. admin-edited values. Parameters are injected from the current container on each run.
     *
     * @param  Closure  $resolver  returns an array of seo.* keys (untyped: Closure(mixed ...) rejects typed params)
     */
    public function siteUsing(Closure $resolver): void
    {
        $this->siteResolver = $resolver;
        Container::getInstance()->forgetInstance(Memo::class);
    }

    /** @param Closure $resolver returns iterable<SitemapEntry>, listed before and winning over seo.sitemap */
    public function sitemapUsing(Closure $resolver): void
    {
        $this->sitemapResolver = $resolver;
    }

    /**
     * Each language's path for a route, for canonicals, hreflang and sitemaps. fn (Route, string $path): null when not
     * localized, else ['path' => canonical path, 'alternates' => [hreflang => path], default first]. Paths only.
     */
    public function alternatesUsing(Closure $resolver): void
    {
        $this->alternatesResolver = $resolver;
    }

    /**
     * @internal
     *
     * @return array{path: string, alternates: non-empty-array<string, string>}|null
     *
     * @throws LogicException a returned value is not a path
     */
    public function alternatesFor(?Route $route, string $path): ?array
    {
        if ($route === null || $this->alternatesResolver === null) {
            return null;
        }

        $resolved = ($this->alternatesResolver)($route, $path);

        if ($resolved === null) {
            return null;
        }

        if (! is_array($resolved) || ! array_key_exists('path', $resolved) || ! is_array($resolved['alternates'] ?? null) || $resolved['alternates'] === []
            || ! array_all($resolved['alternates'], static fn (mixed $path, int|string $code): bool => is_string($code))) {
            throw new LogicException('Seo::alternatesUsing(): the closure returns null or [path => string, alternates => non-empty [code => path]].');
        }

        // One leading slash, so `//host` cannot leave the site. Reject only a leading scheme: `/x/https://y` is a path.
        $clean = static function (mixed $value): string {
            if (! is_string($value) || preg_match('~^[a-z][a-z0-9+.-]*://~i', $value) === 1 || str_contains($value, '?') || str_contains($value, '#')) {
                throw new LogicException('Seo::alternatesUsing(): [' . (is_string($value) ? $value : get_debug_type($value)) . '] is not a path.');
            }

            return '/' . ltrim($value, '/');
        };

        return ['path' => $clean($resolved['path']), 'alternates' => array_map($clean, $resolved['alternates'])];
    }

    /**
     * Merges into this request's Page: each non-blank argument replaces its field, jsonLd appends.
     *
     * @param  list<array<string, mixed>|JsonSerializable>  $jsonLd
     *
     * @throws InvalidArgumentException invalid $canonical or $jsonLd
     */
    public function page(
        ?string $title = null,
        ?string $description = null,
        ?string $image = null,
        ?string $canonical = null,
        ?Robots $robots = null,
        ?bool $paginated = null,
        ?bool $suffixSiteName = null,
        array $jsonLd = [],
    ): void {
        $memo = self::memo();
        $request = Container::getInstance()->make('request');
        $page = $memo->pages[$request] ?? new Page;
        $text = static fn (?string $new, ?string $old): ?string => filled($new) ? $new : $old;

        $memo->pages[$request] = new Page(
            title: $text($title, $page->title),
            description: $text($description, $page->description),
            image: $text($image, $page->image),
            canonical: $text($canonical, $page->canonical),
            robots: $robots ?? $page->robots,
            paginated: $paginated ?? $page->paginated,
            suffixSiteName: $suffixSiteName ?? $page->suffixSiteName,
            jsonLd: [...$page->jsonLd, ...$jsonLd],
        );
    }

    /** @internal The merged Page; null when page() was never called. */
    public function pageFor(Request $request): ?Page
    {
        return self::memo()->pages[$request] ?? null;
    }

    /**
     * Memoised per Request and locale (siteUsing() may read the locale), failures included; rebuilt without a Request.
     *
     * @throws InvalidArgumentException malformed url or disallow entry
     */
    public function site(?Request $request = null): Site
    {
        /** @var Application $app */
        $app = Container::getInstance();
        $build = fn (): Site => self::fromConfig($app, $this->siteResolver === null ? [] : $app->call($this->siteResolver));

        if ($request === null) {
            return $build();
        }

        $memo = self::memo();
        $locale = $app->getLocale();
        $cached = $memo->sites[$request] ?? null;

        if ($cached === null || $cached['locale'] !== $locale) {
            try {
                $site = $build();
            } catch (Throwable $e) {
                $site = $e;
            }

            $memo->sites[$request] = $cached = ['locale' => $locale, 'site' => $site];
        }

        return $cached['site'] instanceof Throwable ? throw $cached['site'] : $cached['site'];
    }

    /**
     * sitemapUsing() entries, then seo.sitemap locs they do not replace; localized locs expand to every alternate.
     *
     * @return Generator<int, SitemapEntry>
     *
     * @throws LogicException a loc on another host, or a sitemapUsing() entry that is not a SitemapEntry
     * @throws InvalidArgumentException a seo.sitemap value that is not a path, URL or route name
     */
    public function sitemap(?Request $request = null): Generator
    {
        $expand = $this->expander($this->site($request));
        $config = [];

        foreach ($this->configLocs() as $loc) {
            $locs = $expand($loc);
            $config[$locs[0]] ??= $locs;
        }

        foreach ($this->sitemapResolver === null ? [] : Container::getInstance()->call($this->sitemapResolver) as $entry) {
            if (! $entry instanceof SitemapEntry) {
                throw new LogicException('Seo::sitemapUsing(): the closure yields SitemapEntry objects, got ' . get_debug_type($entry) . '.');
            }

            $locs = $expand($entry->loc);
            unset($config[$locs[0]]);

            foreach ($locs as $loc) {
                yield new SitemapEntry($loc, $entry->lastModified);
            }
        }

        foreach ($config as $locs) {
            foreach ($locs as $loc) {
                yield new SitemapEntry($loc);
            }
        }
    }

    /** @internal null when nothing is listed. */
    public function sitemapUrl(Site $site): ?string
    {
        return $this->listsSitemap() ? $site->to('/sitemap.xml') : null;
    }

    /** @internal */
    public function sitemapFile(string $name, ?Request $request = null): ?string
    {
        foreach ($this->listsSitemap() ? $this->sitemapFiles($request) : [] as $file => $xml) {
            if ($file === $name) {
                return $xml;
            }
        }

        return null;
    }

    /**
     * One pass, one file in memory; past MAX_URLS, sitemap-{n}.xml then sitemap.xml as their index.
     *
     * @return Generator<string, string> file name => XML
     */
    private function sitemapFiles(?Request $request = null): Generator
    {
        $urls = '';
        $count = 0;

        foreach ($this->sitemap($request) as $entry) {
            if ($count > 0 && $count % self::MAX_URLS === 0) {
                yield 'sitemap-' . intdiv($count, self::MAX_URLS) . '.xml' => self::xml('urlset', $urls);
                $urls = '';
            }

            $urls .= '<url><loc>' . htmlspecialchars($entry->loc, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>'
                . ($entry->lastModified === null ? '' : '<lastmod>' . $entry->lastModified->format(DATE_ATOM) . '</lastmod>')
                . "</url>\n";
            $count++;
        }

        if ($count <= self::MAX_URLS) {
            yield 'sitemap.xml' => self::xml('urlset', $urls);

            return;
        }

        $chunks = intdiv($count - 1, self::MAX_URLS) + 1;
        yield "sitemap-{$chunks}.xml" => self::xml('urlset', $urls);

        $site = $this->site($request);
        $index = '';

        for ($n = 1; $n <= $chunks; $n++) {
            $index .= '<sitemap><loc>' . htmlspecialchars($site->to("/sitemap-{$n}.xml"), ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</loc></sitemap>\n";
        }

        yield 'sitemap.xml' => self::xml('sitemapindex', $index);
    }

    /** @param array<string, mixed> $overrides null falls through to config, '' clears */
    private static function fromConfig(Application $app, array $overrides): Site
    {
        $config = $app->make('config');
        $seo = Arr::whereNotNull($overrides) + (array)$config->get('seo');
        $value = static fn (string $key): mixed => blank($v = $seo[$key] ?? null) ? null : $v;
        $list = static fn (string $key): array => array_values(array_filter((array)($seo[$key] ?? []), filled(...)));

        return new Site(
            name: (string)($value('name') ?? $config->get('app.name')),
            url: (string)($value('url') ?? $config->get('app.url')),
            disallow: $list('disallow'),
            image: $value('image'),
            titleSeparator: $value('title_separator') ?? ' · ',
            indexByDefault: (bool)($value('index_by_default') ?? true),
            logo: $value('logo'),
            alternateNames: $list('alternate_names'),
            sameAs: $list('same_as'),
            googleVerification: $value('google_verification'),
            noindexHosts: $list('noindex_hosts'),
            indexNowKey: $value('index_now_key'),
            organizationType: $value('organization_type') ?? 'Organization',
        );
    }

    private static function memo(): Memo
    {
        return Container::getInstance()->make(Memo::class);
    }

    private function listsSitemap(): bool
    {
        return $this->sitemapResolver !== null || self::configValues() !== [];
    }

    /** @return list<mixed> */
    private static function configValues(): array
    {
        return array_values(array_filter((array)Container::getInstance()->make('config')->get('seo.sitemap'), filled(...)));
    }

    /** @return list<string> */
    private function configLocs(): array
    {
        $routes = $this->router->getRoutes();

        return array_map(static function (mixed $value) use ($routes): string {
            $route = is_string($value) ? $routes->getByName($value) : null;
            $shown = is_scalar($value) ? (string)$value : get_debug_type($value);

            return match (true) {
                is_string($value) && (str_starts_with($value, '/') || str_contains($value, '://')) => $value,
                // Relative lands on the Site's origin; a domain route stays absolute so the host check sees it.
                $route !== null => route($value, absolute: $route->getDomain() !== null),
                default         => throw new InvalidArgumentException("seo.sitemap: [{$shown}] is not a route name. Write paths with a leading '/', e.g. '/{$shown}'."),
            };
        }, self::configValues());
    }

    /** @return Closure(string): non-empty-list<string> a loc's URLs, one per alternatesUsing() alternate */
    private function expander(Site $site): Closure
    {
        // Match routes as the router would, fallbacks last, but never bind: RouteCollection::match() would clobber
        // the current route's parameters.
        $routes = $this->alternatesResolver === null ? [] : $this->router->getRoutes()->get('GET');
        usort($routes, static fn (Route $a, Route $b): int => $a->isFallback <=> $b->isFallback);

        return function (string $loc) use ($site, $routes): array {
            $loc = $site->to($loc);
            $parts = parse_url($loc);

            if (! is_array($parts) || strtolower($parts['host'] ?? '') !== $site->host()) {
                throw new LogicException("Sitemap loc [{$loc}] is not on {$site->host()}: robots.txt advertises the sitemap on the index host only.");
            }

            // Keep the query raw: parse_str() renames `v1.2` to `v1_2` and folds repeated keys.
            $uri = new Uri($loc);
            $path = '/' . trim($uri->getPath(), '/');
            $query = $uri->getQuery() === '' ? '' : '?' . $uri->getQuery();
            $probe = $routes === [] ? null : Request::create($site->to($path) . $query);
            $alternates = $probe === null ? null : $this->alternatesFor(array_find($routes, static fn (Route $route): bool => $route->matches($probe)), $path);

            if ($alternates === null) {
                return [$site->to($path) . $query];
            }

            return array_values(array_map(static fn (string $p): string => $site->to($p) . $query, $alternates['alternates']));
        };
    }

    private static function xml(string $root, string $body): string
    {
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<{$root} xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n{$body}</{$root}>\n";
    }
}
