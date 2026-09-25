<?php

declare(strict_types=1);

namespace Seo\Tests\Fixtures;

use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

/** A RouteMatched listener set before the package boots, as an error tracker naming transactions. */
final class EarlierListenerProvider extends ServiceProvider
{
    /** @var list<?string> */
    public static array $names = [];

    public function boot(Router $router): void
    {
        self::$names = [];
        $router->matched(static function (RouteMatched $event): void {
            self::$names[] = $event->route->getName();
        });
    }
}
