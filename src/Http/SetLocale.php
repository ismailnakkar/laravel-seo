<?php

declare(strict_types=1);

namespace Seo\Http;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use LogicException;
use Seo\LocalizedRoute;
use Symfony\Component\HttpFoundation\Response;

/**
 * Locale from the Route::localized() copy alone (never Accept-Language, cookie, session, IP or user; nothing written).
 * Not in the middleware priority list, so it runs after the app's `web` locale middleware and wins.
 */
final class SetLocale
{
    public function __construct(private readonly Application $app) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->app->setLocale((LocalizedRoute::of($request->route()) ?? throw new LogicException('SetLocale is attached by Route::localized() only.'))->locale);

        return $next($request);
    }
}
