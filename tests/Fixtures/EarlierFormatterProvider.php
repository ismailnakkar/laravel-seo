<?php

declare(strict_types=1);

namespace Seo\Tests\Fixtures;

use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\ServiceProvider;

/** A formatter set before the package boots: it must still run, after the package's. */
final class EarlierFormatterProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(UrlGenerator::class)->formatPathUsing(static fn (string $path): string => str_replace('/legacy', '/renamed', $path));
    }
}
