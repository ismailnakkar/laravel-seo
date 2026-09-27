<?php

declare(strict_types=1);

namespace Seo\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Seo\LocalizedRoute;

/**
 * `/en/terms`, en being the default: a 301 to `/terms`, where the default copy lives. Route::localized() registers one
 * per GET page, so any other `/en/…` path still 404s.
 *
 * @internal
 */
final class RedirectToDefaultCopy
{
    /** @internal Route-action key holding the default code, so ApplyLocale counts the redirect as opening that copy. */
    public const string ACTION = 'seo_default_redirect';

    public function __invoke(Request $request): RedirectResponse
    {
        /** @var Route $route */
        $route = $request->route();
        // One leading slash: `/en//host` would otherwise leave `//host`, which a browser reads as a host.
        $path = '/' . ltrim(LocalizedRoute::withoutPrefix($request->getPathInfo(), (string)$route->getAction(self::ACTION)), '/');
        $query = (string)$request->server->get('QUERY_STRING');

        // Symfony drops Cache-Control from a 301 unless given one, and a browser would then replay it without asking: the
        // next /en would skip ApplyLocale, which records the choice.
        return redirect()->to($path . ($query === '' ? '' : "?{$query}"), 301, ['Cache-Control' => 'no-cache, private']);
    }
}
