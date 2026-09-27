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
 * The signed-in user's side of the language, and the entry redirect. Off a copy: the language the visitor browses (the
 * session, set by opening a Route::localized() copy in another language), else the account's, the browser's, the
 * default. Only the switcher changes an account's language; an account without one takes the visitor's on a page view,
 * once. In `web` straight after AuthenticateSession when two or more locales are configured: the user is only safe to
 * read, and the request to answer, once that has checked the session.
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
        $choice = ResolveLocale::choice($request, $locales, $account);
        $target = $this->entryTarget($request, $locales, $localized, $choice);

        // Before anything is saved: an arrival on the default copy would otherwise save the default over the choice.
        if ($target !== null) {
            return redirect()->to($target);
        }

        // Not a signed link, an <img>, or a sibling's fetch, which can also set Accept-Language.
        $pageView = self::opensThePage($request);
        // A /en/… redirect opens the default copy: typed, it asks for that language, which an entry_redirect on the
        // page it lands on must not then undo.
        $redirect = $request->route() instanceof Route ? $request->route()->getAction(RedirectToDefaultCopy::ACTION) : null;
        $opened = $pageView ? ($localized->locale ?? (is_string($redirect) ? $redirect : null)) : null;

        // Only a change: `auth` sends a new device to the login copy in the browser's language, which must not then
        // outrank the account.
        if ($opened !== null && $opened !== $choice && $request->hasSession()) {
            $request->session()->put(ResolveLocale::SESSION_KEY, $opened);
        }

        // Checked on the row the guard loaded, so a page view costs no query. A user signed in by this request is filled
        // on their next one. Only on a GET: a POST /locale saves the choice itself, which is one write.
        if ($pageView && $request->isMethod('GET') && $account === null && UserLocale::hasColumn($user)) {
            rescue(static fn () => UserLocale::save($user, $opened ?? $choice, unlessSet: $locales));
        }

        $this->app->setLocale($localized->locale ?? $choice);

        return $next($request);
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
     * Never a signed link: its sender chose its language, as for the entry redirect. From another origin, a sibling
     * subdomain included, only a top-level GET: a session cookie also follows an <img>, iframe or fetch from a sibling
     * (from any site when SameSite=None), and an app may rank its CSRF check after this, so a forged POST is refused
     * too late. From this origin a page load or the app's own fetch (Inertia, wire:navigate), never an <img> or iframe,
     * which user content on the site could point at a copy. No Fetch Metadata: an older browser, trusted as this
     * origin. Never a Livewire component update: its persistent middleware replays the page's route with
     * /livewire/update's query, so a signed page would lose its signature.
     */
    private static function opensThePage(Request $request): bool
    {
        $dest = $request->headers->get('Sec-Fetch-Dest');

        return ! $request->query->has('signature') && ! $request->headers->has('X-Livewire')
            && (in_array($request->headers->get('Sec-Fetch-Site'), [null, 'same-origin'], true)
            ? in_array($dest, [null, 'document', 'empty'], true)
            : $request->isMethod('GET') && $dest === 'document');
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
