<?php

declare(strict_types=1);

namespace Seo\Tests\Fixtures;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Deliberately wrong, like cuty's pre-fix middleware: it overrides the URL's locale. */
final class NegotiateLocale
{
    public function __construct(private readonly Application $app) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->app->setLocale($request->session()->get('locale') ?? $request->getPreferredLanguage(['en', 'fr', 'ar', 'es']));

        return $next($request);
    }
}
