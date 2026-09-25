<?php

declare(strict_types=1);

namespace Seo\Console;

use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Exception\MalformedUriException;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Schema;
use Seo\HostRole;
use Seo\Locales;
use Seo\LocalizedRoute;
use Seo\ParsedPage;
use Seo\Seo;
use Seo\Site;
use Seo\Testing\RobotsMatcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'seo:check')]
final class CheckCommand extends Command
{
    protected $signature = 'seo:check {url?*} {--link=*} {--sample=5}';

    protected $description = 'Audit the live robots.txt, sitemap, pages, crawler access and redirects for what a kernel test cannot see';

    /**
     * AI search indexers, and Claude-User. Never named in robots.txt, so a block is the edge's. Spelled as the vendors
     * spell them: edge user-agent rules are case-sensitive.
     */
    private const array AGENTS = [
        'OAI-SearchBot', 'Claude-SearchBot', 'PerplexityBot', 'DuckAssistBot', 'Amzn-SearchBot', 'meta-webindexer',
        'MistralAI-Index', 'Claude-User',
    ];

    /** Organization.logo takes any format Google Images supports. */
    private const array LOGO_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'image/avif', 'image/bmp', 'image/svg+xml'];

    /** Googlebot follows up to 10. */
    private const int MAX_HOPS = 10;

    /** Google: "ideally no more than 3". */
    private const int FEW_HOPS = 3;

    private const string UNRESOLVED = 'could not resolve host';

    private Factory $http;

    /** @var array{FAIL: int, WARN: int, SKIP: int} */
    private array $counts;

    public function handle(Seo $seo, Factory $http): int
    {
        $this->http = $http;
        $this->counts = ['FAIL' => 0, 'WARN' => 0, 'SKIP' => 0];
        $site = $seo->site();
        $sample = filter_var($this->option('sample'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($sample === false) {
            $this->error('--sample must be a positive integer.');

            return self::FAILURE;
        }

        if (in_array(null, $this->option('link'), true)) {
            $this->error('--link needs a URL.');

            return self::FAILURE;
        }

        $hosts = [];

        foreach ($this->argument('url') ?: [$site->url] as $url) {
            $host = parse_url(str_contains($url, '://') ? $url : "//{$url}", PHP_URL_HOST);

            if (! is_string($host) || $host === '') {
                $this->error("[{$url}] is not a URL or a host.");

                return self::FAILURE;
            }

            $hosts[strtolower($host)] = true;
        }

        $this->write("seo:check · site {$site->url} · crawler rows spoof user agents from this machine's IP and are indicative only");
        $this->write();
        $this->languages();

        $sitemapUrl = $seo->sitemapUrl($site);

        foreach (array_keys($hosts) as $host) {
            $role = $site->roleOf($host);
            $expected = $site->robotsTxt($role, $sitemapUrl);
            $this->write("{$host} ({$role->value})");
            $this->robotsTxt($host, $expected);
            $locs = [];

            if ($role === HostRole::index) {
                // The app's body, not the live one: identical once the robots.txt row passes.
                $robots = new RobotsMatcher($expected);
                $locs = $this->sitemap($sitemapUrl, $host, $robots);
                $this->sample($locs, $sample);
                $this->crawlers($site->to('/'), $this->home($site, $host, $robots));
            }

            if ($role === HostRole::noindex) {
                $this->xRobotsTag($host);
            }

            $from = "http://{$host}/";
            $this->redirect('http', $from, $this->fetch($from, ParsedPage::CHROME), ["https://{$host}/", $site->to('/')]);

            if (! str_starts_with($host, 'www.')) {
                $this->www($site, $host, $locs);
            }
        }

        foreach ($this->option('link') as $url) {
            $this->write("link {$url}");
            $this->link($url);
        }

        $exit = $this->counts['FAIL'] > 0 ? self::FAILURE : self::SUCCESS;
        $this->write();
        $this->write(sprintf('%d FAIL, %d WARN, %d SKIP · exit %d', $this->counts['FAIL'], $this->counts['WARN'], $this->counts['SKIP'], $exit));

        return $exit;
    }

    /** Language config only a booted app can check: routes load after every provider's boot, even with route:cache. */
    private function languages(): void
    {
        if (Locales::configured() === null) {
            return;
        }

        $config = $this->laravel->make('config');
        $routes = $this->laravel->make(Router::class)->getRoutes();
        $this->write('languages');

        foreach ((array)$config->get('seo.entry_redirect') as $name) {
            $name = is_scalar($name) ? (string)$name : get_debug_type($name);
            $localized = LocalizedRoute::of($routes->getByName($name));
            $isDefaultCopy = $localized !== null && $localized->locale === $localized->locales->default;
            $this->row('entry_redirect', $isDefaultCopy ? 'PASS' : 'FAIL', $isDefaultCopy ? $name : "[{$name}] is not the name of a Route::localized() route");
        }

        $column = $config->get('seo.user_locale');

        if (is_string($column) && $column !== '') {
            $this->row('user_locale', ...self::userColumn($config, $column));
        }

        $this->write();
    }

    /** @return array{string, string} status, detail */
    private static function userColumn(Repository $config, string $column): array
    {
        $provider = $config->get('auth.guards.' . $config->get('auth.defaults.guard') . '.provider');
        $model = is_string($provider) ? $config->get("auth.providers.{$provider}.model") : null;

        if (! is_string($model) || ! is_a($model, Model::class, true)) {
            return ['WARN', "no Eloquent user model to check [{$column}] on"];
        }

        $user = new $model;
        $table = $user->getTable();

        try {
            $exists = Schema::connection($user->getConnectionName())->hasColumn($table, $column);
        } catch (QueryException) {
            return ['WARN', "could not reach the database to check {$table}.{$column}"];
        }

        return $exists ? ['PASS', "{$table}.{$column}"] : ['FAIL', "{$table} has no column [{$column}]"];
    }

    private function robotsTxt(string $host, string $expected): void
    {
        $url = "https://{$host}/robots.txt";
        $response = $this->fetch($url, ParsedPage::CHROME);

        if (is_string($response)) {
            $this->row('robots.txt', 'FAIL', "{$url}: {$response}");

            return;
        }

        $body = $response->body();

        $failure = match (true) {
            $response->status() !== 200                                               => "{$response->status()} (expected 200; crawlers read a 4xx as no rules and a 5xx as disallow-all)",
            ParsedPage::mediaType($response->header('Content-Type')) !== 'text/plain' => 'Content-Type ' . ($response->header('Content-Type') ?: 'missing') . ' (expected text/plain)',
            $body === $expected                                                       => null,
            default                                                                   => self::firstDifference($expected, $body),
        };

        $this->row('robots.txt', $failure === null ? 'PASS' : 'FAIL', $failure ?? strlen($body) . " bytes, matches the app's body");
    }

    /** @return list<string> the urlset's locs, or an index's first file's, even on FAIL: sample() and www() read them */
    private function sitemap(?string $url, string $host, RobotsMatcher $robots): array
    {
        if ($url === null) {
            $this->row('sitemap', 'SKIP', 'no sitemap configured');

            return [];
        }

        [$failure, $index, $locs] = $this->sitemapFile($url, $host, $robots, true);

        if (! $index || $failure !== null) {
            $this->row('sitemap', $failure === null ? 'PASS' : 'FAIL', $failure ?? "{$url} 200, " . count($locs) . " URLs, all on {$host}");

            // A failed index's locs are files, not pages.
            return $index ? [] : $locs;
        }

        $files = $locs;
        $first = [];
        $count = 0;

        foreach ($files as $i => $file) {
            [$failure, , $fileLocs] = $this->sitemapFile($file, $host, $robots, false);
            $count += count($fileLocs);

            if ($i === 0) {
                $first = $fileLocs;
            }

            if ($failure !== null) {
                break;
            }
        }

        $this->row('sitemap', $failure === null ? 'PASS' : 'FAIL', $failure ?? "{$url} 200, {$count} URLs in " . count($files) . " files, all on {$host}");

        return $first;
    }

    /** @return array{string|null, bool, list<string>} the failure, whether it is an index, its locs */
    private function sitemapFile(string $url, string $host, RobotsMatcher $robots, bool $mayIndex): array
    {
        $response = $this->fetch($url, ParsedPage::CHROME);

        if (is_string($response)) {
            return ["{$url}: {$response}", false, []];
        }

        $type = ParsedPage::mediaType($response->header('Content-Type'));
        [$root, $locs] = ParsedPage::sitemap($response->body()) ?? [null, []];
        $offHost = array_find($locs, static fn (string $loc): bool => strtolower((string)parse_url($loc, PHP_URL_HOST)) !== $host);
        // Guarded: allows() throws on a relative loc's path.
        $disallowed = $offHost === null ? array_find($locs, static fn (string $loc): bool => ! $robots->allows('Googlebot', ParsedPage::robotsPath($loc))) : null;

        $failure = match (true) {
            $response->status() !== 200                                 => "{$url} {$response->status()} (expected 200)",
            ! in_array($type, ParsedPage::SITEMAP_TYPES, true)          => "{$url} Content-Type " . ($response->header('Content-Type') ?: 'missing') . ' (expected application/xml or text/xml)',
            $root === null || (! $mayIndex && $root === 'sitemapindex') => "{$url} is not a sitemaps.org <urlset>" . ($mayIndex ? ' or <sitemapindex>' : ''),
            $offHost !== null                                           => "{$url}: {$offHost} is not on {$host}",
            $disallowed !== null                                        => "{$url}: {$disallowed} is disallowed for Googlebot by robots.txt",
            default                                                     => null,
        };

        return [$failure, $root === 'sitemapindex', $locs];
    }

    /** @param list<string> $locs */
    private function sample(array $locs, int $size): void
    {
        if ($locs === []) {
            $this->row('sample', 'SKIP', 'no sitemap URLs to sample');
            $this->row('descriptions', 'SKIP', 'no sitemap URLs to sample');

            return;
        }

        $count = min($size, count($locs));
        $failure = null;
        $answered = 0;
        $undescribed = [];

        // The first, then evenly spaced.
        foreach (range(0, $count - 1) as $i) {
            $loc = $locs[intdiv($i * count($locs), $count)];
            $response = $this->fetch($loc, ParsedPage::GOOGLEBOT);
            $page = is_string($response) || $response->status() !== 200 ? null : ParsedPage::parse($response->body());
            $failure ??= self::unfit($loc, $response, $page);

            if ($page !== null) {
                $answered++;

                if ($page->description === null) {
                    $undescribed[] = $loc;
                }
            }
        }

        $this->row('sample', $failure === null ? 'PASS' : 'FAIL', $failure ?? "{$count}/{$count}: 200, self-canonical, indexable, no SVG og:image (Googlebot smartphone, no cookies, no redirects)");

        // Answered pages only: the sample row already reports the rest.
        match (true) {
            $answered === 0     => $this->row('descriptions', 'SKIP', 'no sample page answered 200'),
            $undescribed === [] => $this->row('descriptions', 'PASS', "{$answered}/{$answered} have a meta description"),
            default             => $this->row('descriptions', 'WARN', count($undescribed) . "/{$answered} without a meta description: " . implode(', ', $undescribed)),
        };
    }

    /** Judged against the local Site, whose settings must match production's. Returns the crawler baseline. */
    private function home(Site $site, string $host, RobotsMatcher $robots): Response|string
    {
        $url = $site->to('/');
        $response = $this->fetch($url, ParsedPage::CHROME);
        $problems = [];
        $passes = [];

        if ($site->name === 'Laravel') {
            $problems[] = ['WARN', "Site name is Laravel's default: set APP_NAME or seo.name"];
        }

        if (is_string($response) || $response->status() !== 200) {
            $problems[] = ['FAIL', is_string($response) ? "{$url}: {$response}" : "{$url} {$response->status()} (expected 200)"];
        } else {
            $page = ParsedPage::parse($response->body());

            if ($page->title !== null && mb_stripos($page->title, $site->name) !== false) {
                $passes[] = "title contains \"{$site->name}\"";
            } else {
                $problems[] = ['WARN', $page->title === null ? 'no <title> in <head>' : "title \"{$page->title}\" does not contain Site name \"{$site->name}\""];
            }

            if (array_any($page->jsonLd, static fn (array $node): bool => in_array('WebSite', (array)($node['@type'] ?? []), true) && ($node['name'] ?? null) === $site->name)) {
                $passes[] = "WebSite.name \"{$site->name}\"";
            } else {
                $problems[] = ['FAIL', "no WebSite JSON-LD with name \"{$site->name}\""];
            }
        }

        // Blank means unset: the logo is admin-edited and never validated.
        if (filled($site->logo)) {
            $logo = $site->to($site->logo);
            $image = $this->fetch($logo, ParsedPage::CHROME);
            $got = is_string($image) ? $image : trim("{$image->status()} " . ParsedPage::mediaType($image->header('Content-Type')));

            if (! is_string($image) && $image->status() === 200 && in_array(ParsedPage::mediaType($image->header('Content-Type')), self::LOGO_TYPES, true)) {
                $passes[] = "logo {$logo} {$got}";
            } else {
                $problems[] = ['FAIL', "logo {$logo} {$got} (expected 200 image/png, jpeg, webp, gif, avif, bmp or svg+xml)"];
            }

            // Another host's logo answers to its own robots.txt.
            if (strtolower((string)parse_url($logo, PHP_URL_HOST)) === $host && ! $robots->allows('Googlebot', ParsedPage::robotsPath($logo))) {
                $problems[] = ['FAIL', "logo {$logo} is disallowed for Googlebot by robots.txt"];
            }
        }

        foreach ($problems as [$status, $detail]) {
            $this->row('home', $status, $detail);
        }

        if ($problems === []) {
            $this->row('home', 'PASS', implode('; ', $passes));
        }

        return $response;
    }

    private function crawlers(string $url, Response|string $baseline): void
    {
        $baseline = self::accepted($baseline);

        // A refused baseline cannot tell a bot block from the page.
        if (is_string($baseline)) {
            $this->row('crawlers', 'SKIP', "baseline Chrome got {$baseline}");

            return;
        }

        $chrome = $baseline->status();
        $clean = true;

        foreach (self::AGENTS as $token) {
            if (is_string($refused = self::accepted($this->fetch($url, "Mozilla/5.0 (compatible; {$token}/1.0)")))) {
                $this->row('crawlers', 'FAIL', "{$token} {$refused} while Chrome gets {$chrome}");
                $clean = false;
            }
        }

        if ($clean) {
            $this->row('crawlers', 'PASS', count(self::AGENTS) . " AI search/assistant agents get Chrome's {$chrome}");
        }
    }

    /** Also probes a static file: nginx serves those without the header PHP adds. */
    private function xRobotsTag(string $host): void
    {
        foreach (['/' => false, '/favicon.ico' => true] as $path => $static) {
            $url = "https://{$host}{$path}";
            $response = $this->fetch($url, ParsedPage::CHROME);

            if (is_string($response)) {
                $this->row('x-robots-tag', 'FAIL', "{$url}: {$response}");

                return;
            }

            $tag = $response->header('X-Robots-Tag');
            $got = "{$url} {$response->status()}";
            $missing = $tag === '' ? 'has no X-Robots-Tag' : "has X-Robots-Tag: {$tag} without noindex";

            match (true) {
                self::noindex($response) => $this->row('x-robots-tag', 'PASS', "{$got} carries X-Robots-Tag: {$tag}"),
                ! $static                => $this->row('x-robots-tag', 'FAIL', "{$got} {$missing}"),
                $response->successful()  => $this->row('x-robots-tag', 'WARN', "{$got} {$missing} (served by nginx: see README Hosts)"),
                default                  => $this->row('x-robots-tag', 'SKIP', $got),
            };
        }
    }

    /**
     * SKIPs a www host that does not resolve or connect: nothing to consolidate. Any other fetch error (TLS, timeout)
     * FAILs. The index host also probes its first non-root sitemap path, which an alias catch-all can answer before
     * a redirect rule does.
     *
     * @param  list<string>  $locs
     */
    private function www(Site $site, string $host, array $locs): void
    {
        $paths = array_map(static fn (string $loc): string => (string)parse_url($loc, PHP_URL_PATH), $locs);

        foreach (array_unique(['/', array_find($paths, static fn (string $path): bool => $path !== '' && $path !== '/') ?? '/']) as $path) {
            $from = "https://www.{$host}{$path}";
            $response = $this->fetch($from, ParsedPage::CHROME);

            if (is_string($response) && ($response === self::UNRESOLVED || str_starts_with($response, 'cURL error 7:'))) {
                $this->row('www', 'SKIP', "www.{$host}: {$response}");

                return;
            }

            // Not Site::to(): a live `//x` path would read as the host x.
            $this->redirect('www', $from, $response, ["https://{$host}{$path}", $site->url . $path]);
        }
    }

    /**
     * One 301/308 hop to one of $targets: only a permanent redirect consolidates signals.
     *
     * @param  list<string>  $targets
     */
    private function redirect(string $check, string $from, Response|string $response, array $targets): void
    {
        $targets = array_values(array_unique($targets));
        $location = is_string($response) ? '' : $response->header('Location');
        $got = is_string($response) ? $response : trim("{$response->status()} {$location}");
        // Location is a reference: `//H/` from https://www.H/ is https://H/.
        $to = $location === '' ? null : ParsedPage::resolve($from, $location);

        if (! is_string($response) && in_array($response->status(), [301, 308], true) && $to !== null && array_any($targets, static fn (string $target): bool => ParsedPage::sameUrl($target, $to))) {
            $this->row($check, 'PASS', "{$from} → {$got}");
        } else {
            $this->row($check, 'FAIL', "{$from} → {$got} (expected one 301/308 to " . implode(' or ', $targets) . ')');
        }
    }

    /** No cookie jar: a cookieless client must still land within a few hops, or crawlers never do. */
    private function link(string $url): void
    {
        $chain = [$url];

        while (true) {
            $response = $this->fetch($url, ParsedPage::GOOGLEBOT);
            $hops = count($chain) - 1;
            $location = is_string($response) || ! $response->redirect() ? '' : $response->header('Location');

            if ($location === '' || $hops === self::MAX_HOPS) {
                break;
            }

            if (($url = ParsedPage::resolve($url, $location)) === null) {
                $this->row('cookieless', 'FAIL', "{$response->status()} to unparseable Location {$location} after " . self::trail($chain));

                return;
            }

            $loop = array_any($chain, static fn (string $seen): bool => ParsedPage::sameUrl($seen, $url));
            $chain[] = $url;

            if ($loop) {
                $this->row('cookieless', 'FAIL', sprintf('loop after %d hops: %s', $hops + 1, implode(' → ', $chain)));

                return;
            }
        }

        $trail = self::trail($chain);

        match (true) {
            is_string($response)        => $this->row('cookieless', 'FAIL', "{$response} after {$trail}"),
            $response->status() !== 200 => $this->row('cookieless', 'FAIL', "{$response->status()} after {$trail}"),
            $hops > self::FEW_HOPS      => $this->row('cookieless', 'WARN', "200 after {$hops} hops (Google advises " . self::FEW_HOPS . ' or fewer): ' . implode(' → ', $chain)),
            default                     => $this->row('cookieless', 'PASS', "200 after {$trail}"),
        };
    }

    /** One crawler-style hop; a string says why there is no response. */
    private function fetch(string $url, string $userAgent): Response|string
    {
        try {
            return $this->http->withoutRedirecting()->timeout(10)->withOptions(['cookies' => false])->withUserAgent($userAgent)->get($url);
        } catch (RequestException $e) { // a 4xx/5xx whose body broke off
            return $e->response;
        } catch (ConnectionException|TransferException|MalformedUriException $e) {
            // Before Laravel 13.26, PendingRequest wraps only ConnectException and RequestException.
            $message = $e->getMessage();

            return str_contains($message, 'Could not resolve host') ? self::UNRESOLVED : (strstr($message, ' (see ', true) ?: $message);
        }
    }

    private function row(string $check, string $status, string $detail): void
    {
        if (isset($this->counts[$status])) {
            $this->counts[$status]++;
        }

        $this->write('  ' . str_pad("{$check} ", 16, '.') . " {$status} {$detail}");
    }

    /** Raw: a live page's `<title>` would otherwise reach the console formatter as a style tag. */
    private function write(string $line = ''): void
    {
        $this->output->writeln($line, OutputInterface::OUTPUT_RAW);
    }

    private static function unfit(string $loc, Response|string $response, ?ParsedPage $page): ?string
    {
        if (is_string($response) || $page === null) {
            return is_string($response) ? "{$loc}: {$response}" : "{$loc}: {$response->status()} (expected 200)";
        }

        return match (true) {
            count($page->canonicals) !== 1                    => "{$loc}: " . count($page->canonicals) . ' canonicals in <head> (expected 1)',
            ! ParsedPage::sameUrl($page->canonicals[0], $loc) => "{$loc}: canonical {$page->canonicals[0]} is not the loc",
            $page->titles > 1                                 => "{$loc}: {$page->titles} <title> elements in <head> (expected 1)",
            in_array('noindex', $page->robots, true)          => "{$loc}: robots meta noindex",
            self::noindex($response)                          => "{$loc}: X-Robots-Tag {$response->header('X-Robots-Tag')}",
            $page->svgOgImage()                               => "{$loc}: og:image {$page->ogImage} is an SVG",
            default                                           => null,
        };
    }

    /** The 2xx response, or why the request was refused: its error or status. */
    private static function accepted(Response|string $response): Response|string
    {
        return is_string($response) || $response->successful() ? $response : (string)$response->status();
    }

    /** Header lines kept apart: a `crawler:` scope ends with its line. */
    private static function noindex(Response $response): bool
    {
        return in_array('noindex', ParsedPage::headerRobots($response->toPsrResponse()->getHeader('X-Robots-Tag')), true);
    }

    /** @param non-empty-list<string> $chain */
    private static function trail(array $chain): string
    {
        $hops = count($chain) - 1;

        return sprintf('%d %s: %s', $hops, $hops === 1 ? 'hop' : 'hops', implode(' → ', $chain));
    }

    private static function firstDifference(string $expected, string $live): string
    {
        $expected = explode("\n", $expected);
        $live = explode("\n", $live);

        // Terminates: the bodies differ and explode() loses nothing.
        for ($i = 0; ($expected[$i] ?? null) === ($live[$i] ?? null); $i++);

        return sprintf("differs from the app's body at line %d: expected %s, got %s", $i + 1, self::quote($expected[$i] ?? null), self::quote($live[$i] ?? null));
    }

    /** Escapes control and non-ASCII octets: a CR or BOM must show, and none may move the cursor. */
    private static function quote(?string $line): string
    {
        return $line === null ? 'end of file' : '"' . addcslashes($line, "\0..\37\177..\377") . '"';
    }
}
