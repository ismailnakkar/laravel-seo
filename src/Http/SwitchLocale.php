<?php

declare(strict_types=1);

namespace Seo\Http;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\UrlGenerationException;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use LogicException;
use Seo\Locales;
use Seo\LocalizedRoute;
use Seo\UserLocale;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * POST /locale: saves the visitor's language and returns them to `to` in it. A signed `to` is signed again only while
 * its own signature is valid, so nothing unsigned ever is.
 */
final class SwitchLocale
{
    public function __invoke(Request $request, Application $app, Router $router, UrlGenerator $url): RedirectResponse
    {
        $locales = Locales::configured() ?? throw new NotFoundHttpException;
        $code = (string)$request->validate(['locale' => ['required', 'string', Rule::in($locales->codes)]])['locale'];

        if ($request->hasSession()) {
            $request->session()->put(ResolveLocale::SESSION_KEY, $code);
        }

        UserLocale::save($request->user(), $code);

        $target = self::target($request, $request->input('to'), $code, $app, $router, $url);

        return redirect()->to(self::isPath($target) ? $target : '/', 303);
    }

    /** `to` in $code, by the first rule that applies. The caller checks the result is still a path on this host. */
    private static function target(Request $request, mixed $to, string $code, Application $app, Router $router, UrlGenerator $url): string
    {
        if (! self::isPath($to)) {
            return '/';
        }

        try {
            $probe = Request::create($request->getSchemeAndHttpHost() . $to);
            $route = $router->getRoutes()->match($probe);
        } catch (BadRequestException) {
            return '/';
        } catch (HttpExceptionInterface) {
            return $to;
        }

        $localized = $route->isFallback ? null : LocalizedRoute::of($route);

        if ($localized === null) {
            return $to;
        }

        if ($probe->query->has('signature')) {
            return self::signedAgain($request, $probe, $route, $localized, $code, $app, $router, $url) ?? $to;
        }

        $query = (string)$probe->server->get('QUERY_STRING');

        try {
            return $localized->path($probe->getPathInfo(), $code) . ($query === '' ? '' : "?{$query}");
        } catch (LogicException) {
            return $to;
        }
    }

    /**
     * $code's copy of a signed page, signed again for the same route, its own parameters (never a ->defaults() one)
     * and expiry. null when the route has no name, the signature isn't valid under the current key (never a retired
     * one: renewing past rotation would defeat it), or — the signing-oracle guard — the result isn't on this request's
     * own scheme and host, re-matched, as a same-domain, same-URI, $code-locale copy of the very route that was matched.
     */
    private static function signedAgain(Request $request, Request $probe, Route $route, LocalizedRoute $localized, string $code, Application $app, Router $router, UrlGenerator $url): ?string
    {
        $name = $localized->name($route);

        if ($name === null || str_ends_with($name, '.') || str_contains($name, 'generated::')) {
            return null;
        }

        // A previous app.key validates the request (rotation grace) but must never itself renew a signature.
        if (! $url->withKeyResolver(static fn () => $app->make('config')->get('app.key'))->hasValidSignature($probe)) {
            return null;
        }

        $expires = $probe->query('expires');
        $previous = $app->getLocale();
        $app->setLocale($code);

        try {
            $signed = $url->signedRoute(
                $name,
                Arr::only($route->parameters(), $route->parameterNames()) + Arr::except($probe->query(), ['signature', 'expires']),
                // A timestamp, not an int: an int means "seconds from now" and would push the expiry out.
                is_numeric($expires) ? Carbon::createFromTimestamp((int)$expires) : null,
            );
        } catch (UrlGenerationException|InvalidArgumentException) {
            // $name now belongs to another route, one this page's parameters don't fit.
            return null;
        } finally {
            $app->setLocale($previous);
        }

        try {
            $signedRequest = Request::create($signed);
        } catch (BadRequestException) {
            return null;
        }

        // $name can resolve to a route on another host or domain group sharing it: the signature is only ever valid
        // for $signed's own host, so that must be this request's, checked before trusting anything else about it.
        if (strcasecmp($signedRequest->getSchemeAndHttpHost(), $request->getSchemeAndHttpHost()) !== 0) {
            return null;
        }

        try {
            $landing = $router->getRoutes()->match($signedRequest);
        } catch (BadRequestException|HttpExceptionInterface) {
            return null;
        }

        $landingLocalized = $landing->isFallback ? null : LocalizedRoute::of($landing);

        if (
            $landingLocalized === null
            || $landingLocalized->locale !== $code
            || $landing->getDomain() !== $route->getDomain()
            || $landingLocalized->unprefixedUri($landing) !== $localized->unprefixedUri($route)
        ) {
            return null;
        }

        return $signedRequest->getRequestUri();
    }

    /** One leading slash, no backslash, control character or space: anything else a browser may read as another host. */
    private static function isPath(mixed $value): bool
    {
        return is_string($value)
            && str_starts_with($value, '/')
            && ! str_starts_with($value, '//')
            && preg_match('/[\\\\\x00-\x20\x7F]/', $value) === 0;
    }
}
