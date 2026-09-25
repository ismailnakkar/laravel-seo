<?php

declare(strict_types=1);

namespace Seo\Http;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Jaybizzle\CrawlerDetect\CrawlerDetect;
use Seo\Locales;
use Seo\LocalizedRoute;
use Seo\UserLocale;
use Symfony\Component\HttpFoundation\Response;

/**
 * The request's language: a Route::localized() copy's own, else the visitor's choice. Only the switcher changes the
 * choice once it is made. In `web` straight after StartSession when two or more locales are configured.
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
        $user = $request->user();
        $choice = self::choice($request, $locales, $localized, $user);
        $page = $localized->locale ?? $choice;

        if ($request->hasSession()) {
            $request->session()->put(self::SESSION_KEY, $choice);
        }

        if ($user !== null && UserLocale::of($user, $locales) === null) {
            rescue(static fn () => UserLocale::save($user, $choice));
        }

        $this->app->setLocale($page);

        $target = $this->entryTarget($request, $locales, $localized, $choice);

        if ($target !== null) {
            return redirect()->to($target);
        }

        $response = $next($request);

        // Signed in during this request (a sign-up, a sign-in): an account without a language takes the page's. The
        // user before $next counts, so an admin impersonating a member later in the stack never writes theirs.
        if ($user === null && ($signedIn = $request->user()) !== null && UserLocale::of($signedIn, $locales) === null) {
            rescue(static fn () => UserLocale::save($signedIn, $page));
        }

        return $response;
    }

    /** The account's, the session's, a session's first /fr/… page's, the browser's, else the default. */
    private static function choice(Request $request, Locales $locales, ?LocalizedRoute $localized, mixed $user): string
    {
        $saved = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        return UserLocale::of($user, $locales)
            ?? (is_string($saved) && in_array($saved, $locales->codes, true) ? $saved : null)
            ?? ($localized !== null && $localized->locale !== $locales->default ? $localized->locale : null)
            ?? $locales->preferredBy($request)
            ?? $locales->default;
    }

    /** The choice's copy of an entry_redirect page, for a visitor arriving from outside the site; null to render. */
    private function entryTarget(Request $request, Locales $locales, ?LocalizedRoute $localized, string $choice): ?string
    {
        $route = $request->route();
        $name = $route instanceof Route ? $route->getName() : null;

        if (
            $localized === null
            || $localized->locale !== $locales->default
            || $choice === $locales->default
            || ! in_array($name, (array)$this->app->make('config')->get('seo.entry_redirect'), true)
            || ! ($request->isMethod('GET') || $request->isMethod('HEAD'))
            || $request->query->has('signature') // a signed URL pins its path
            || self::fromInsideTheSite($request)
            || self::isCrawler($request)
        ) {
            return null;
        }

        $query = (string)$request->server->get('QUERY_STRING');

        return $localized->path($request->getPathInfo(), $choice) . ($query === '' ? '' : "?{$query}");
    }

    /** Sec-Fetch-Site, or where a browser sends none (Safari before 16.4, plain HTTP), a Referer on this host. */
    private static function fromInsideTheSite(Request $request): bool
    {
        $site = $request->headers->get('Sec-Fetch-Site');

        if ($site !== null) {
            return $site === 'same-origin';
        }

        $referer = parse_url((string)$request->headers->get('Referer'), PHP_URL_HOST);

        return is_string($referer) && strcasecmp($referer, $request->getHost()) === 0;
    }

    /** Built from this request's own server values: the default constructor reads $_SERVER, stale under Octane. */
    private static function isCrawler(Request $request): bool
    {
        $userAgent = (string)$request->userAgent();

        return $userAgent !== '' && new CrawlerDetect($request->server->all(), $userAgent)->isCrawler($userAgent);
    }
}
