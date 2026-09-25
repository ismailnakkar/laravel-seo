<?php

declare(strict_types=1);

namespace Seo;

use InvalidArgumentException;
use JsonSerializable;

/** What one page says about itself, merged from its Seo::page() and @seo(...) calls; <x-seo::head /> renders it. */
final readonly class Page
{
    /**
     * @param  list<array<string, mixed>|JsonSerializable>  $jsonLd  raw schema.org nodes, one script each
     *
     * @throws InvalidArgumentException $canonical is set and is neither an absolute http(s) URL nor a root-relative path;
     *                                  $jsonLd is not a list of arrays and JsonSerializable
     */
    public function __construct(
        public ?string $title = null,          // null or blank: the head's title prop, else @section('title'), else the site name
        public ?string $description = null,    // null or blank: the head's description prop, else @section('description'), else none
        public ?string $image = null,          // og:image, an absolute URL or a path on Site::$url; null: config('seo.image')
        public ?string $canonical = null,      // a path goes on Site::$url. Indexable: replaces the built canonical, drops hreflang. Noindex: og:url only
        public Robots $robots = Robots::index,
        public bool $paginated = false,        // keep ?page=N (N > 1) in the canonical and hreflang URLs
        public bool $suffixSiteName = true,    // home and link interstitials pass false
        public array $jsonLd = [],
    ) {
        if ($canonical !== null && preg_match('~^(https?://[^/?#\s]+|/(?!/))~i', $canonical) !== 1) {
            throw new InvalidArgumentException("Page::\$canonical must be an absolute http(s) URL or a root-relative path, got [{$canonical}].");
        }

        // Here, not at render, where it would surface as a TypeError far from the call that made it.
        if (! array_is_list($jsonLd) || ! array_all($jsonLd, static fn (mixed $node): bool => is_array($node) || $node instanceof JsonSerializable)) {
            throw new InvalidArgumentException('Page::$jsonLd must be a list of nodes, each an array or JsonSerializable: wrap a single node as [[...]].');
        }
    }
}
