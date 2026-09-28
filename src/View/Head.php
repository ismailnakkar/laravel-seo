<?php

declare(strict_types=1);

namespace Seo\View;

use Illuminate\Container\Container;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\View\Component;
use Illuminate\View\Factory;
use JsonSerializable;
use Seo\HostRole;
use Seo\Memo;
use Seo\Robots;
use Seo\Seo;
use Seo\Site;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * Prints a marker that fill() replaces once the response exists, so a page() or @seo in any view counts.
 *
 * @internal The tag is the API.
 */
final class Head extends Component
{
    /** Raw inside <script>, so HTML characters are escaped; invalid UTF-8 (admin input) becomes U+FFFD, not a 500. */
    private const int JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    /** Blade passes an enclosing component's attributes here, so services are resolved in render(), not injected. */
    public function __construct(
        private readonly Htmlable|string|null $title = null,
        private readonly Htmlable|string|null $description = null,
    ) {}

    public function render(): Htmlable
    {
        $container = Container::getInstance();
        /** @var Factory $view */
        $view = $container->make('view');
        $memo = $container->make(Memo::class);
        $request = $container->make('request');
        // Random, so page content cannot forge it; one per render, so the head keeps its render's fallbacks. Keyed
        // whole, as a digits-only nonce would become an int key.
        $marker = '<!--seo-head:' . bin2hex(random_bytes(8)) . '-->';
        $memo->heads[$request] ??= [];
        $memo->heads[$request][$marker] = [
            'title'       => self::fallback($this->title, 'title', $view),
            'description' => self::fallback($this->description, 'description', $view),
        ];

        return new HtmlString($marker);
    }

    /** The first of this request's markers becomes the head; the rest, from a nested full render, go. */
    public static function fill(SymfonyResponse $response, SymfonyRequest $request): void
    {
        $container = Container::getInstance();
        $memo = $container->make(Memo::class);
        // A kernel sub-request during rendering replaces the container's request.
        /** @var Request $request */
        $request = $request instanceof Request && isset($memo->heads[$request]) ? $request : $container->make('request');

        // response($array) is also a Response, with a JSON body.
        if (! isset($memo->heads[$request]) || ! $response instanceof Response || ! str_contains((string)$response->headers->get('Content-Type', 'text/html'), 'html')) {
            return;
        }

        $heads = $memo->heads[$request];
        $content = (string)$response->getContent();
        preg_match_all('/<!--seo-head:[0-9a-f]{16}-->/', $content, $matches);
        $marker = array_find($matches[0], static fn (string $marker): bool => isset($heads[$marker]));

        if ($marker === null) {
            return;
        }

        unset($memo->heads[$request]);
        $head = $heads[$marker];
        $seo = $container->make(Seo::class);
        $error = $response->getStatusCode() >= 400;

        $site = null;

        try {
            $site = $seo->site($request);
            $html = self::build($seo, $site, $request, $error, $head['title'], $head['description']);
        } catch (Throwable $e) {
            // Production serves an uncached noindex head instead of throwing when the Site fails (nothing vouches for
            // the host) or on an error response (maybe filled after the kernel's try, where a throw escapes). The
            // page's own errors stay a 500, which engines retry.
            throw_unless(app()->isProduction() && ($site === null || $error), $e);
            $memo->report($e);
            $response->headers->set('Cache-Control', 'no-store');
            $title = self::first([$error ? null : $seo->pageFor($request)?->title, $head['title']]) ?? config('app.name');
            $html = '<title>' . e(is_string($title) ? $title : '') . "</title>\n<meta name=\"robots\" content=\"noindex, nofollow\">\n";
        }

        // setContent() replaces the View that assertViewHas() reads.
        $original = $response->original;
        $response->setContent(Str::before($content, $marker) . $html . str_replace(array_keys($heads), '', Str::after($content, $marker)));
        $response->original = $original;
    }

    private static function build(Seo $seo, Site $site, Request $request, bool $error, ?string $titleFallback, ?string $descriptionFallback): string
    {
        // An error response does not serve the content its Page was set for.
        $page = $error ? null : $seo->pageFor($request);

        $robots = match (true) {
            $site->roleOf($request->getHost()) === HostRole::noindex => Robots::none,
            $error                                                   => Robots::noindex,
            $page !== null                                           => $page->robots,
            default                                                  => $site->indexByDefault ? Robots::index : Robots::noindex,
        };
        // The closure runs once per head, and only when the canonical comes from the request path.
        $resolved = $robots->indexable() && $page?->canonical === null ? $seo->alternatesFor($request->route(), '/' . trim($request->getPathInfo(), '/')) : null;
        $canonical = $robots->indexable() ? $site->canonical($request, $page, $resolved) : null;
        $home = $canonical !== null && $site->isHome($canonical);
        $title = self::first([$page?->title, $titleFallback]);
        $fullTitle = match (true) {
            $title === null               => $site->name,
            $page->suffixSiteName ?? true => $title . $site->titleSeparator . $site->name,
            default                       => $title,
        };
        $image = $page->image ?? $site->image;

        // Rendered here, so Blade cannot merge the component's slots into the view data and shadow $title.
        return view('seo::head', [
            'site'         => $site,
            'title'        => $fullTitle,
            'description'  => self::first([$page?->description, $descriptionFallback]),
            'robots'       => $robots,
            'canonical'    => $canonical,
            'alternates'   => $canonical === null ? [] : $site->alternates($request, $page, $resolved),
            'ogUrl'        => $canonical ?? ($page?->canonical === null ? null : $site->to($page->canonical)),
            'image'        => $image === null ? null : $site->to($image),
            'imageAlt'     => $page?->image === null ? $site->name : ($title ?? $site->name),
            'verification' => $home && filled($site->googleVerification) ? $site->googleVerification : null,
            'scripts'      => array_map(
                static fn (array|JsonSerializable $node): string => json_encode($node, self::JSON),
                [...($home ? [self::graph($site)] : []), ...($page->jsonLd ?? [])],
            ),
        ])->render();
    }

    /** Props, slots and sections are HTML: title="{{ $t }}" and @section('title', $t) have already escaped it. */
    private static function fallback(Htmlable|string|null $prop, string $section, Factory $view): ?string
    {
        $prop = $prop instanceof Htmlable ? $prop->toHtml() : (string)$prop;
        $decode = static fn (string $html): string => html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return self::first([$decode($prop), $decode($view->yieldContent($section))]);
    }

    /** @param list<?string> $values */
    private static function first(array $values): ?string
    {
        return array_find(array_map(static fn (?string $value): string => trim((string)$value), $values), static fn (string $value): bool => $value !== '');
    }

    /** @return array<string, mixed> */
    private static function graph(Site $site): array
    {
        $shared = [
            'name'          => $site->name,
            'alternateName' => $site->alternateNames ?: null,
            'url'           => $site->to('/'),
        ];

        return ['@context' => 'https://schema.org', '@graph' => [
            // Google wants the most specific Organization subtype.
            Arr::whereNotNull([
                '@type' => $site->organizationType,
                ...$shared,
                'logo'   => filled($site->logo) ? $site->to($site->logo) : null,
                'sameAs' => $site->sameAs ?: null,
            ]),
            Arr::whereNotNull(['@type' => 'WebSite', ...$shared]),
        ]];
    }
}
