<?php

declare(strict_types=1);

namespace Seo\Tests\Fixtures;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A member's refusal ranked after the session check, like cuty's gate: its body is the language it renders in. */
final class RequireRecentSignIn
{
    public function __construct(private readonly Application $app) {}

    public function handle(Request $request, Closure $next): Response
    {
        return new Response($this->app->getLocale(), 423);
    }
}
