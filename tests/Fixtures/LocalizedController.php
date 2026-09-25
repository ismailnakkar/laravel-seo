<?php

declare(strict_types=1);

namespace Seo\Tests\Fixtures;

/** A controller, so action() has something to look up. */
final class LocalizedController
{
    /** @return array{locale: string, route: string, action: string} */
    public function terms(): array
    {
        return ['locale' => app()->getLocale(), 'route' => route('terms'), 'action' => action([self::class, 'terms'])];
    }
}
