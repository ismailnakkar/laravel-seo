<?php

declare(strict_types=1);

namespace Seo;

use DateTimeInterface;

final readonly class SitemapEntry
{
    public function __construct(
        public string $loc,
        public ?DateTimeInterface $lastModified = null,
    ) {}
}
