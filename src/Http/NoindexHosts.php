<?php

declare(strict_types=1);

namespace Seo\Http;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Seo\HostRole;
use Seo\Memo;
use Seo\Seo;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Adds X-Robots-Tag: noindex, nofollow on noindex hosts. Fails open: a broken Site is reported, never a 500. */
final class NoindexHosts
{
    public function __construct(private readonly Seo $seo) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            $noindex = $this->seo->site($request)->roleOf($request->getHost()) === HostRole::noindex;
        } catch (Throwable $e) {
            Container::getInstance()->make(Memo::class)->report($e);

            return $response;
        }

        if ($noindex) {
            // Appended to any existing one: engines apply the most restrictive.
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow', false);
        }

        return $response;
    }
}
