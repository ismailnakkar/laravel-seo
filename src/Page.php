<?php

declare(strict_types=1);

namespace Seo;

use InvalidArgumentException;
use JsonSerializable;

/** One page's SEO values, merged from its page() and @seo calls. */
final readonly class Page
{
    /**
     * @param  list<array<string, mixed>|JsonSerializable>  $jsonLd
     *
     * @throws InvalidArgumentException $canonical is not an absolute http(s) URL or root-relative path, or $jsonLd is
     *                                  not a list of arrays and JsonSerializable
     */
    public function __construct(
        public ?string $title = null,
        public ?string $description = null,
        public ?string $image = null,
        public ?string $canonical = null,
        public Robots $robots = Robots::index,
        public bool $paginated = false,
        public bool $suffixSiteName = true,
        public array $jsonLd = [],
    ) {
        if ($canonical !== null && preg_match('~^(https?://[^/?#\s]+|/(?!/))~i', $canonical) !== 1) {
            throw new InvalidArgumentException("Page::\$canonical must be an absolute http(s) URL or a root-relative path, got [{$canonical}].");
        }

        // Checked here, not at render, so the error points at the caller.
        if (! array_is_list($jsonLd) || ! array_all($jsonLd, static fn (mixed $node): bool => is_array($node) || $node instanceof JsonSerializable)) {
            throw new InvalidArgumentException('Page::$jsonLd must be a list of nodes, each an array or JsonSerializable: wrap a single node as [[...]].');
        }
    }
}
