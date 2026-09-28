<?php

declare(strict_types=1);

namespace Seo\Tests\Fixtures;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Sets the locale as a localization package does, so <html lang> follows the URL. */
final class SetLocale
{
    public function __construct(private readonly Application $app) {}

    public function handle(Request $request, Closure $next, string $locale): Response
    {
        $this->app->setLocale($locale);

        return $next($request);
    }
}
