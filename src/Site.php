<?php

declare(strict_types=1);

namespace Seo;

use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Built from config('seo') and the siteUsing() overrides; validates only code-sourced values. Every URL the package
 * emits is built on it, never on the request host.
 */
final readonly class Site
{
    /** Lower-cased origin, no trailing slash. */
    public string $url;

    /** @var list<string> lower-cased ASCII (punycode) hosts, no trailing dot; null, '' and duplicates dropped */
    public array $noindexHosts;

    /**
     * @param  list<string>  $disallow  robots.txt path prefixes for EVERY host; end them in '/', or `/settings` also
     *                                  blocks a short host's alias `settingsX`
     * @param  list<string>  $alternateNames  WebSite and Organization alternateName, in preference order
     * @param  list<string>  $sameAs  Organization.sameAs: official profile URLs
     * @param  list<string|null>  $noindexHosts  hosts or URLs, as config gives them
     *
     * @throws InvalidArgumentException $url is not an absolute http(s) origin (no path beyond '/', no query, fragment, userinfo
     *                                  or trailing dot), or its host has no punycode form; a disallow entry that is not an
     *                                  RFC 9309 path-pattern: '/' then UTF-8 with no whitespace, control character or '#'
     */
    public function __construct(
        public string $name,                  // title suffix, og:site_name, WebSite/Organization name
        string $url,
        public array $disallow = [],
        public ?string $image = null,         // og:image when the Page sets none: an absolute URL or a path on $url
        public string $titleSeparator = ' · ',
        public bool $indexByDefault = true,   // false: a request that never calls Seo::page() renders noindex, follow
        public ?string $logo = null,          // Organization.logo: URL or path; stable, ≥112px, any Google Images format
        public array $alternateNames = [],
        public array $sameAs = [],
        public ?string $googleVerification = null,
        array $noindexHosts = [],
        public ?string $indexNowKey = null,   // public by design (served as a file); format checked where used
        public string $organizationType = 'Organization', // the home graph's schema.org Organization subtype
    ) {
        foreach ($disallow as $path) {
            // A crawler reads `#` as a comment, which widens `/a#b` to `/a`, and a raw space never matches a request.
            if (! is_string($path) || preg_match('/^\/[^\x00-\x20#\x7F]*$/Du', $path) !== 1) {
                throw new InvalidArgumentException('Site::$disallow (seo.disallow): every entry must be an RFC 9309 path-pattern: start with / and hold no whitespace, control character or #.');
            }
        }

        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $origin = in_array($scheme, ['http', 'https'], true) && ($parts['host'] ?? '') !== ''
            ? $scheme . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '')
            : null;

        // The rebuilt origin must spell the whole value: anything left is a path, query, fragment or userinfo.
        if ($origin === null || ! in_array(strtolower($url), [$origin, $origin . '/'], true) || str_ends_with($parts['host'], '.') || ($host = self::ascii($parts['host'])) === null) {
            throw new InvalidArgumentException("Site::\$url (seo.url, else app.url) must be an absolute http(s) origin with no path, query, fragment, userinfo or trailing dot, got [{$url}].");
        }

        $this->url = $scheme . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');

        $hosts = [];

        foreach ($noindexHosts as $value) {
            $host = is_string($value) && $value !== '' ? parse_url(str_contains($value, '://') ? $value : "//{$value}", PHP_URL_HOST) : null;

            if (is_string($host) && ($host = self::ascii($host)) !== null) {
                $hosts[] = rtrim($host, '.');
            }
        }

        $this->noindexHosts = array_values(array_unique($hosts));
    }

    /** Lower-cased host of $url. */
    public function host(): string
    {
        return (string)parse_url($this->url, PHP_URL_HOST);
    }

    /**
     * Index host first, so a noindex entry naming it (a misconfigured env value) cannot noindex the site. A request
     * host may keep an FQDN's trailing dot (`dl.test.`).
     */
    public function roleOf(string $host): HostRole
    {
        $host = rtrim(strtolower($host), '.');

        return match (true) {
            $host === $this->host()                    => HostRole::index,
            in_array($host, $this->noindexHosts, true) => HostRole::noindex,
            default                                    => HostRole::crawl,
        };
    }

    /**
     * Absolute URLs unchanged; `//host/x` on $url's scheme; anything else is a path (with any query) on $url. Never
     * pass it a request URI: `//x` would leave the site.
     */
    public function to(string $path): string
    {
        // A leading scheme, not "contains ://": `/out?to=https://x` is a path.
        return match (true) {
            preg_match('~^[a-z][a-z0-9+.-]*://~i', $path) === 1 => $path,
            str_starts_with($path, '//')                        => parse_url($this->url, PHP_URL_SCHEME) . ':' . $path,
            default                                             => $this->url . '/' . ltrim($path, '/'),
        };
    }

    /** Page::$canonical on $url; otherwise the request path on $url, keeping only ?page=N (N > 1) if paginated. */
    public function canonical(Request $request, ?Page $page = null): string
    {
        if ($page?->canonical !== null) {
            return $this->to($page->canonical);
        }

        [$path, $query] = $this->pathAndPage($request, $page);

        return $this->to($path) . $query;
    }

    /**
     * @return array<string, string> hreflang => href in codes order, x-default last; [] outside Route::localized() or
     *                               with a canonical override
     */
    public function alternates(Request $request, ?Page $page = null): array
    {
        $localized = LocalizedRoute::of($request->route());

        if ($localized === null || $page?->canonical !== null) {
            return [];
        }

        [$path, $query] = $this->pathAndPage($request, $page);
        $alternates = [];

        foreach ($localized->locales->codes as $code) {
            $alternates[$code] = $this->to($localized->path($path, $code)) . $query;
        }

        return $alternates + ['x-default' => $alternates[$localized->locales->default]];
    }

    /** No agent is ever named: a named group replaces `*` for it (RFC 9309 §2.2.1). Sitemap line on HostRole::index with a URL only. */
    public function robotsTxt(HostRole $role, ?string $sitemapUrl): string
    {
        $lines = ['User-agent: *', ...array_map(static fn (string $p): string => rtrim("Disallow: {$p}"), $this->disallow ?: [''])];

        return implode("\n", $role === HostRole::index && $sitemapUrl !== null ? [...$lines, '', "Sitemap: {$sitemapUrl}"] : $lines) . "\n";
    }

    /** Index host, path '' or '/', any query. */
    public function isHome(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && strtolower($parts['host'] ?? '') === $this->host()
            && in_array($parts['path'] ?? '', ['', '/'], true);
    }

    /** Requests carry the punycode host (Symfony rejects raw UTF-8), so compare in that form. */
    private static function ascii(string $host): ?string
    {
        $host = strtolower($host);

        return preg_match('/[^\x00-\x7F]/', $host) === 1 ? (idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: null) : $host;
    }

    /** @return array{string, string} */
    private function pathAndPage(Request $request, ?Page $page): array
    {
        // getPathInfo() has already dropped /index.php.
        $path = '/' . trim($request->getPathInfo(), '/');
        // Normalise the copy's prefix as the router decoded it: /%66r/faq → /fr/faq.
        $localized = LocalizedRoute::of($request->route());
        $path = $localized?->path($path, $localized->locale) ?? $path;

        // The canonical names the page Laravel's paginator serves: '+2' is page 2, '02' is page 1.
        $p = filter_var($request->query('page'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 2]]);

        return [$path, ($page->paginated ?? false) && $p !== false ? "?page={$p}" : ''];
    }
}
