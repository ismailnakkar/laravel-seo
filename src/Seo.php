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

/**
 * Builds the Site from config('seo') and holds each request's Page. Not final: apps mock it, and Mockery refuses final
 * classes.
 */
class Seo
{
    /** sitemaps.org's per-file limit. */
    private const int MAX_URLS = 50_000;

    private ?Closure $siteResolver = null;

    private ?Closure $sitemapResolver = null;

    public function __construct(private readonly Router $router) {}

    /**
     * seo.* config overrides, e.g. values an admin edits: fn (Settings $s) => ['name' => $s->siteName()]. Parameters
     * are injected when the closure runs, from the current container (under Octane, the request's sandbox), never one
     * captured at boot. Resets the memo.
     *
     * @param  Closure  $resolver  returns an array of seo.* keys. No typed signature: Closure(mixed ...) rejects
     *                             fn (Settings $s) in the caller's static analysis.
     */
    public function siteUsing(Closure $resolver): void
    {
        $this->siteResolver = $resolver;
        Container::getInstance()->forgetInstance(Memo::class);
    }

    /** @param Closure $resolver returns an iterable of SitemapEntry, listed first; wins over a seo.sitemap loc it shares */
    public function sitemapUsing(Closure $resolver): void
    {
        $this->sitemapResolver = $resolver;
    }

    /**
     * Merges into the current request's Page, for <x-seo::head />: each non-null argument replaces its field (a blank
     * string counts as unset), jsonLd nodes append. A view's @seo runs after its controller's page(), so it wins. The
     * head reads the Page once the response exists: a call after that, such as in middleware after $next(), is lost.
     *
     * @param  list<array<string, mixed>|JsonSerializable>  $jsonLd
     *
     * @throws InvalidArgumentException $canonical is neither an absolute http(s) URL nor a root-relative path; $jsonLd is
     *                                  not a list of arrays and JsonSerializable
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

    /** The merged Page; null when page() was never called. */
    public function pageFor(Request $request): ?Page
    {
        return self::memo()->pages[$request] ?? null;
    }

    /**
     * The configured languages in config order, for a switcher. [] below two.
     *
     * @return list<Language>
     */
    public function languages(): array
    {
        $locales = Locales::configured();

        if ($locales === null) {
            return [];
        }

        /** @var Application $app */
        $app = Container::getInstance();
        $names = (array)$app->make('config')->get('seo.locales');

        return array_map(
            static fn (string $code): Language => new Language($code, (string)$names[$code], $code === $app->getLocale()),
            $locales->codes,
        );
    }

    /**
     * Memoised per Request and locale when a Request is given (a siteUsing() closure may read the locale, and the
     * locale can change after the first build within a request); rebuilt on every call without one.
     *
     * @throws InvalidArgumentException for a malformed code-sourced value: url, a disallow entry
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

        if ($cached !== null && $cached['locale'] === $locale) {
            return $cached['site'];
        }

        $site = $build();
        $memo->sites[$request] = ['locale' => $locale, 'site' => $site];

        return $site;
    }

    /**
     * The sitemapUsing() entries, then the config('seo.sitemap') locs they do not list: on a shared loc the resolver's
     * entry, with its lastmod, wins. Lazy: holds the config list only. Absolute locs on Site::host(), path and query
     * percent-encoded per RFC 3986 (existing escapes kept). A loc on any copy of a Route::localized() route expands to
     * one per locale, default first.
     *
     * @return Generator<int, SitemapEntry>
     *
     * @throws LogicException for a loc on another host
     * @throws InvalidArgumentException for a seo.sitemap value that is neither a path, a URL nor a route name
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

    /** robots.txt's Sitemap URL; null without seo.sitemap or sitemapUsing(). */
    public function sitemapUrl(Site $site): ?string
    {
        return $this->listsSitemap() ? $site->to('/sitemap.xml') : null;
    }

    /** @internal A file the sitemap routes serve; null: none. */
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
     * One pass, one file in memory: past 50,000 URLs sitemap-1.xml… then sitemap.xml as their index.
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

    /** @param array<string, mixed> $overrides seo.* keys over config; null falls through, '' clears */
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
                // Relative, so Site::to() puts it on the Site's origin; absolute on a domain route, so another host
                // fails the host check.
                $route !== null => route($value, absolute: $route->getDomain() !== null),
                default         => throw new InvalidArgumentException("seo.sitemap: [{$shown}] is not a route name. Write paths with a leading '/', e.g. '/{$shown}'."),
            };
        }, self::configValues());
    }

    /** @return Closure(string): non-empty-list<string> a loc's URLs, one per locale on a localized route */
    private function expander(Site $site): Closure
    {
        // The route the router would pick, fallbacks last, expands the loc only if localized; an app without one never
        // builds a probe. Matched, never bound: RouteCollection::match() would overwrite the current route's parameters.
        $routes = $this->router->getRoutes()->get('GET');
        usort($routes, static fn (Route $a, Route $b): int => $a->isFallback <=> $b->isFallback);
        $routes = array_any($routes, static fn (Route $route): bool => LocalizedRoute::of($route) !== null) ? $routes : [];

        return static function (string $loc) use ($site, $routes): array {
            $loc = $site->to($loc);
            $parts = parse_url($loc);

            if (! is_array($parts) || strtolower($parts['host'] ?? '') !== $site->host()) {
                throw new LogicException("Sitemap loc [{$loc}] is not on {$site->host()}: robots.txt advertises the sitemap on the index host only.");
            }

            // Never parse the query: parse_str() renames `v1.2` to `v1_2` and folds repeated keys.
            $uri = new Uri($loc);
            $path = '/' . trim($uri->getPath(), '/');
            $query = $uri->getQuery() === '' ? '' : '?' . $uri->getQuery();
            $probe = $routes === [] ? null : Request::create($site->to($path) . $query);
            $localized = $probe === null ? null : LocalizedRoute::of(array_find($routes, static fn (Route $route): bool => $route->matches($probe)));

            if ($localized === null) {
                return [$site->to($path) . $query];
            }

            $codes = [$localized->locales->default, ...array_diff($localized->locales->codes, [$localized->locales->default])];

            return array_map(static fn (string $code): string => $site->to($localized->path($path, $code)) . $query, $codes);
        };
    }

    private static function xml(string $root, string $body): string
    {
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<{$root} xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n{$body}</{$root}>\n";
    }
}
