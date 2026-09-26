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
 * The request's language: a Route::localized() copy's own, else the visitor's choice. Only the switcher changes the
 * choice once it is made. In `web` straight after StartSession when two or more locales are configured, so it must never
 * read the user: restoring a remember-me cookie writes the login into the session before AuthenticateSession has checked
 * it. ApplyLocale brings in the account.
 */
final class ResolveLocale
{
    public const string SESSION_KEY = 'seo.locale';

    public function __construct(private readonly Application $app) {}

    public function handle(Request $request, Closure $next): Response
    {
        $locales = Locales::configured();

        if ($locales === null) {
            return $next($request);
        }

        $localized = LocalizedRoute::of($request->route());
        $choice = self::choice($request, $locales, $localized);

        if ($request->hasSession()) {
            $request->session()->put(self::SESSION_KEY, $choice);
        }

        $this->app->setLocale($localized->locale ?? $choice);

        return $next($request);
    }

    /** @internal The session's, a session's first /fr/… page's, the browser's, else the default. */
    public static function choice(Request $request, Locales $locales, ?LocalizedRoute $localized): string
    {
        $saved = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        return (is_string($saved) && in_array($saved, $locales->codes, true) ? $saved : null)
            ?? ($localized !== null && $localized->locale !== $locales->default ? $localized->locale : null)
            ?? $locales->preferredBy($request)
            ?? $locales->default;
    }
}
