<?php

declare(strict_types=1);

namespace Seo;

use DateTimeInterface;

final readonly class SitemapEntry
{
    public function __construct(
        public string $loc,                              // absolute URL on the index host, or a path
        public ?DateTimeInterface $lastModified = null,  // the content's real last change; never now()
    ) {}
}
