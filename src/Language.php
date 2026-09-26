<?php

declare(strict_types=1);

namespace Seo;

/** One entry of Seo::languages(): current is the language on screen. Its label is the app's own text. */
final readonly class Language
{
    public function __construct(public string $code, public bool $current) {}
}
