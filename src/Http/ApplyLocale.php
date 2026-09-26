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
 * The signed-in user's side of the language and the entry redirect: the account's code beats the visitor's choice,
 * and opening a Route::localized() copy makes its language the choice, in the session and the account. In `web`
 * straight after AuthenticateSession when two or more locales are configured: the user is only safe to read, and the
 * request to answer, once that has checked the session.
 */
final class ApplyLocale
{
    public function __construct(private readonly Application $app) {}

    public function handle(Request $request, Closure $next): Response
    {
        $locales = Locales::configured();

        if ($locales === null) {
            return $next($request);
        }

        $localized = LocalizedRoute::of($request->route());
        $user = $request->user();
        $account = UserLocale::of($user, $locales);
        $choice = $account ?? ResolveLocale::choice($request, $locales, $localized);
        $target = $this->entryTarget($request, $locales, $localized, $choice);

        // Before anything is saved: an arrival on the default copy would otherwise save the default over the choice.
        if ($target !== null) {
            return redirect()->to($target);
        }

        $page = $localized->locale ?? $choice;
        // null off a copy, or when this request is not the visitor opening it (a signed link, an <img> of it).
        $opened = self::opensThePage($request) ? $localized?->locale : null;
        $saved = $opened ?? $choice;

        if ($request->hasSession()) {
            $request->session()->put(ResolveLocale::SESSION_KEY, $saved);
        }

        // Only on a change, never every page view: an empty account, or a copy in another language.
        if ($user !== null && $account !== $saved) {
            rescue(static fn () => UserLocale::save($user, $saved));
        }

        $this->app->setLocale($page);

        $response = $next($request);

        // Signed in during this request (a sign-up, a sign-in): a copy opened replaces the account's language, and
        // anything else only fills an empty one, as the guest's choice never beats the account. The browser's copy is
        // no choice: `auth` sends a new device's guest to route('login') in it. The user before $next counts, so an
        // admin impersonating a member later in the stack never writes theirs.
        if ($user === null && ($signedIn = $request->user()) !== null) {
            $account = UserLocale::of($signedIn, $locales);
            $chosen = $opened === ($locales->preferredBy($request) ?? $locales->default) ? null : $opened;
            $code = $chosen ?? $account ?? $saved;

            if ($account !== $code) {
                rescue(static fn () => UserLocale::save($signedIn, $code));
            }
        }

        return $response;
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

    /**
     * @internal Never a signed link: its sender chose its language, as for the entry redirect. From another site only a
     * top-level GET: a SameSite=None session cookie also follows an <img>, iframe or fetch from any site, and an app
     * may rank its CSRF check after this, so a forged POST is refused too late. From this site a page load or the app's
     * own fetch (Inertia, wire:navigate), never an <img> or iframe, which user content on the site could point at a
     * copy. No Sec-Fetch-Dest: a browser without Fetch Metadata. Never a Livewire component update: its persistent
     * middleware replays the page's route with /livewire/update's query, so a signed page would lose its signature.
     */
    public static function opensThePage(Request $request): bool
    {
        $dest = $request->headers->get('Sec-Fetch-Dest');

        return ! $request->query->has('signature') && ! $request->headers->has('X-Livewire')
            && ($request->headers->get('Sec-Fetch-Site') === 'cross-site'
            ? $request->isMethod('GET') && $dest === 'document'
            : in_array($dest, [null, 'document', 'empty'], true));
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
