<?php

declare(strict_types=1);

namespace Seo\Http;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Seo\Locales;
use Seo\LocalizedRoute;
use Symfony\Component\HttpFoundation\Response;

/**
 * The request's language before the user is known: a Route::localized() copy's own, else the one the visitor browses,
 * the browser's, the default. In `web` straight after StartSession when two or more locales are configured, so it must
 * never read the user: restoring a remember-me cookie writes the login into the session before AuthenticateSession has
 * checked it. It writes nothing; ApplyLocale brings in the account and records a copy opened.
 */
final class ResolveLocale
{
    /** Not seo.locale: 0.3 sessions hold a browser guess under it, which would outrank the account. */
    public const string SESSION_KEY = 'seo.browsing';

    public function __construct(private readonly Application $app) {}

    public function handle(Request $request, Closure $next): Response
    {
        $locales = Locales::configured();

        if ($locales === null) {
            return $next($request);
        }

        $this->app->setLocale(LocalizedRoute::of($request->route())->locale ?? self::choice($request, $locales));

        return $next($request);
    }

    /** @internal The language the visitor browses (the session's), else the account's, the browser's, the default. */
    public static function choice(Request $request, Locales $locales, ?string $account = null): string
    {
        $browsing = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        return (is_string($browsing) && in_array($browsing, $locales->codes, true) ? $browsing : null)
            ?? $account
            ?? $locales->preferredBy($request)
            ?? $locales->default;
    }
}
