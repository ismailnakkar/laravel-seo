<?php

declare(strict_types=1);

namespace Seo\Tests\Fixtures;

use Illuminate\Support\Facades\Route;

/** A controller, so action() has something to look up. */
final class LocalizedController
{
    /** @return array{locale: string, route: string, action: string, name: ?string, is: bool, routeIs: bool} */
    public function terms(): array
    {
        return [
            'locale'  => app()->getLocale(),
            'route'   => route('terms'),
            'action'  => action([self::class, 'terms']),
            'name'    => Route::currentRouteName(),
            'is'      => Route::is('terms'),
            'routeIs' => request()->routeIs('terms'),
        ];
    }
}
