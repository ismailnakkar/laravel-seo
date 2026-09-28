<?php

declare(strict_types=1);

namespace Seo;

use Illuminate\Http\Request;
use InvalidArgumentException;

/** The site as configured. Every URL the package emits is built on it, never on the request host. */
final readonly class Site
{
    /** Lower-cased origin, no trailing slash. */
    public string $url;

    /** @var list<string> lower-cased punycode hosts, no trailing dot */
    public array $noindexHosts;

    /**
     * @param  list<string>  $disallow
     * @param  list<string>  $alternateNames
     * @param  list<string>  $sameAs
     * @param  list<string|null>  $noindexHosts  hosts or URLs
     *
     * @throws InvalidArgumentException $url is not a bare http(s) origin, or a disallow entry is not an RFC 9309 path
     */
    public function __construct(
        public string $name,
        string $url,
        public array $disallow = [],
        public ?string $image = null,
        public string $titleSeparator = ' · ',
        public bool $indexByDefault = true,
        public ?string $logo = null,
        public array $alternateNames = [],
        public array $sameAs = [],
        public ?string $googleVerification = null,
        array $noindexHosts = [],
        public ?string $indexNowKey = null,
        public string $organizationType = 'Organization',
    ) {
        foreach ($disallow as $path) {
            // `#` starts a comment (widening `/a#b` to `/a`) and a raw space never matches.
            if (! is_string($path) || preg_match('/^\/[^\x00-\x20#\x7F]*$/Du', $path) !== 1) {
                throw new InvalidArgumentException('Site::$disallow (seo.disallow): every entry must be an RFC 9309 path-pattern: start with / and hold no whitespace, control character or #.');
            }
        }

        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $origin = in_array($scheme, ['http', 'https'], true) && ($parts['host'] ?? '') !== ''
            ? $scheme . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '')
            : null;
        $host = self::ascii((string)($parts['host'] ?? ''));

        // Anything beyond the rebuilt origin is a path, query, fragment or userinfo.
        if ($origin === null || ! in_array(strtolower($url), [$origin, $origin . '/'], true) || str_ends_with($parts['host'], '.') || $host === null) {
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

    public function host(): string
    {
        return (string)parse_url($this->url, PHP_URL_HOST);
    }

    /** @internal Index host checked first, so a misconfigured noindex entry cannot noindex the site. */
    public function roleOf(string $host): HostRole
    {
        // A request host may keep an FQDN's trailing dot (`dl.test.`).
        $host = rtrim(strtolower($host), '.');

        return match (true) {
            $host === $this->host()                    => HostRole::index,
            in_array($host, $this->noindexHosts, true) => HostRole::noindex,
            default                                    => HostRole::crawl,
        };
    }

    /** Absolute URLs unchanged, `//host/x` on $url's scheme, anything else a path on $url. Never pass a request URI. */
    public function to(string $path): string
    {
        // A leading scheme, not "contains ://": `/out?to=https://x` is a path.
        return match (true) {
            preg_match('~^[a-z][a-z0-9+.-]*://~i', $path) === 1 => $path,
            str_starts_with($path, '//')                        => parse_url($this->url, PHP_URL_SCHEME) . ':' . $path,
            default                                             => $this->url . '/' . ltrim($path, '/'),
        };
    }

    /**
     * @internal
     *
     * @param  array{path: string, alternates: non-empty-array<string, string>}|null  $resolved  Seo::alternatesFor() this request
     */
    public function canonical(Request $request, ?Page $page = null, ?array $resolved = null): string
    {
        if ($page?->canonical !== null) {
            return $this->to($page->canonical);
        }

        // getPathInfo() has already dropped /index.php.
        $path = '/' . trim($request->getPathInfo(), '/');
        // The closure normalises an encoded language prefix: /%66r/faq → /fr/faq.
        $path = $resolved['path'] ?? $path;

        return $this->to($path) . $this->pageQuery($request, $page);
    }

    /**
     * @internal
     *
     * @param  array{path: string, alternates: non-empty-array<string, string>}|null  $resolved  Seo::alternatesFor() this request
     * @return array<string, string> hreflang => href, x-default (the first) last
     */
    public function alternates(Request $request, ?Page $page = null, ?array $resolved = null): array
    {
        if ($page?->canonical !== null || $resolved === null) {
            return [];
        }

        $query = $this->pageQuery($request, $page);
        $alternates = array_map(fn (string $path): string => $this->to($path) . $query, $resolved['alternates']);

        return $alternates + ['x-default' => reset($alternates)];
    }

    /** @internal One `*` group: a named group would replace it for that agent (RFC 9309 §2.2.1). */
    public function robotsTxt(HostRole $role, ?string $sitemapUrl): string
    {
        // An empty Disallow allows everything.
        $disallow = $this->disallow === [] ? ['Disallow:'] : array_map(static fn (string $p): string => "Disallow: {$p}", $this->disallow);
        $lines = ['User-agent: *', ...$disallow];

        return implode("\n", $role === HostRole::index && $sitemapUrl !== null ? [...$lines, '', "Sitemap: {$sitemapUrl}"] : $lines) . "\n";
    }

    /** @internal */
    public function isHome(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && strtolower($parts['host'] ?? '') === $this->host()
            && in_array($parts['path'] ?? '', ['', '/'], true);
    }

    /** @internal Punycode, as request hosts are (Symfony rejects raw UTF-8). */
    public static function ascii(string $host): ?string
    {
        $host = strtolower($host);

        return preg_match('/[^\x00-\x7F]/', $host) === 1 ? (idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: null) : $host;
    }

    private function pageQuery(Request $request, ?Page $page): string
    {
        // As Laravel's paginator reads it: '+2' is page 2, '02' is page 1.
        $p = filter_var($request->query('page'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 2]]);

        return ($page->paginated ?? false) && $p !== false ? "?page={$p}" : '';
    }
}
