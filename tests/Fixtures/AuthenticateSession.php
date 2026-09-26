<?php

declare(strict_types=1);

namespace Seo\Tests\Fixtures;

use Illuminate\Session\Middleware\AuthenticateSession as LaravelAuthenticateSession;

/** An app's own session check, like cuty's App\Http\Middleware\AuthenticateSession. */
final class AuthenticateSession extends LaravelAuthenticateSession {}
