<?php

declare(strict_types=1);

namespace Seo;

/** One entry of Seo::languages(): current is the language on screen. */
final readonly class Language
{
    public function __construct(public string $code, public string $name, public bool $current) {}
}
