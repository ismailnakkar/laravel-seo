<?php

declare(strict_types=1);

namespace Seo\View;

use Illuminate\Container\Container;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;
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

/**
 * <x-seo::head />, at the top of <head> after charset and viewport. It prints a marker that fill() replaces once the
 * response exists, so a page() or @seo anywhere in the views counts. Robots come from the Page, else from
 * seo.index_by_default; the title and description fall back to the props, then to `@section('title')` and
 * `@section('description')`. An error response (status 400 and up) ignores the Page and renders noindex.
 */
final class Head extends Component
{
    /**
     * Output goes raw into <script>, so every HTML-significant character is escaped. Invalid UTF-8 in an admin-edited
     * value becomes U+FFFD instead of a 500; depth and NAN still throw.
     */
    private const int JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    /**
     * Blade hands an enclosing component's undeclared attributes to this constructor, so it takes nothing but the
     * fallbacks and resolves its services in render().
     */
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
        // Random, so page content cannot forge the marker.
        $nonce = $memo->heads[$request]['nonce'] ?? bin2hex(random_bytes(8));
        $memo->heads[$request] = [
            'nonce'       => $nonce,
            'title'       => self::fallback($this->title, 'title', $view),
            'description' => self::fallback($this->description, 'description', $view),
        ];

        return new HtmlString("<!--seo-head:{$nonce}-->");
    }

    /** @internal The first marker becomes the head; the rest, from a nested full render, go. */
    public static function fill(SymfonyResponse $response, SymfonyRequest $request): void
    {
        $container = Container::getInstance();
        $memo = $container->make(Memo::class);
        // A kernel sub-request made while the page rendered has replaced the container's request.
        /** @var Request $request */
        $request = $request instanceof Request && isset($memo->heads[$request]) ? $request : $container->make('request');

        // JsonResponse, StreamedResponse and BinaryFileResponse are not this class; response($array) is, with a JSON body.
        if (! isset($memo->heads[$request]) || ! $response instanceof Response || ! str_contains((string)$response->headers->get('Content-Type', 'text/html'), 'html')) {
            return;
        }

        $head = $memo->heads[$request];
        $parts = explode("<!--seo-head:{$head['nonce']}-->", (string)$response->getContent());

        if (count($parts) === 1) {
            return;
        }

        unset($memo->heads[$request]);
        $html = self::build($container->make(Seo::class), $request, $response->getStatusCode() >= 400, $head['title'], $head['description']);
        // setContent() replaces the View that assertViewHas() reads.
        $original = $response->original;
        $response->setContent(array_shift($parts) . $html . implode('', $parts));
        $response->original = $original;
    }

    private static function build(Seo $seo, Request $request, bool $error, ?string $titleFallback, ?string $descriptionFallback): string
    {
        $site = $seo->site($request);
        // An error response does not serve the content its Page was set for.
        $page = $error ? null : $seo->pageFor($request);

        $robots = match (true) {
            $site->roleOf($request->getHost()) === HostRole::noindex => Robots::none,
            $error                                                   => Robots::noindex,
            $page !== null                                           => $page->robots,
            default                                                  => $site->indexByDefault ? Robots::index : Robots::noindex,
        };
        $canonical = $robots->indexable() ? $site->canonical($request, $page) : null;
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
            'alternates'   => $canonical === null ? [] : $site->alternates($request, $page),
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

    /**
     * The first non-blank value, trimmed.
     *
     * @param  list<?string>  $values
     */
    private static function first(array $values): ?string
    {
        return array_find(array_map(static fn (?string $value): string => trim((string)$value), $values), static fn (string $value): bool => $value !== '');
    }

    /** @return array<string, mixed> the home page's Organization and WebSite */
    private static function graph(Site $site): array
    {
        return ['@context' => 'https://schema.org', '@graph' => [
            // Google wants the most specific Organization subtype.
            Arr::whereNotNull([
                '@type'         => $site->organizationType,
                'name'          => $site->name,
                'alternateName' => $site->alternateNames ?: null,
                'url'           => $site->to('/'),
                'logo'          => filled($site->logo) ? $site->to($site->logo) : null,
                'sameAs'        => $site->sameAs ?: null,
            ]),
            Arr::whereNotNull([
                '@type'         => 'WebSite',
                'name'          => $site->name,
                'alternateName' => $site->alternateNames ?: null,
                'url'           => $site->to('/'),
            ]),
        ]];
    }
}
