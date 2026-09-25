<?php

declare(strict_types=1);

namespace Seo\Http;

use Closure;
use Illuminate\Http\Request;
use Seo\HostRole;
use Seo\Seo;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * X-Robots-Tag: noindex, nofollow on HostRole::noindex hosts, appended to any existing one: engines apply the most
 * restrictive. Fails open: a throwing Seo::site() is reported (even if logging throws too), never a 500.
 */
final class NoindexHosts
{
    public function __construct(private readonly Seo $seo) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            $noindex = $this->seo->site($request)->roleOf($request->getHost()) === HostRole::noindex;
        } catch (Throwable $e) {
            rescue(static fn () => report($e), report: false);

            return $response;
        }

        if ($noindex) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow', false);
        }

        return $response;
    }
}
