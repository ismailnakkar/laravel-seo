<?php

declare(strict_types=1);

namespace Seo\Tests;

use Closure;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Seo\Locales;
use Seo\LocalizedRoute;
use Seo\SeoServiceProvider;
use Seo\Tests\Fixtures\EarlierFormatterProvider;
use Seo\Tests\Fixtures\EarlierListenerProvider;
use Seo\Tests\Fixtures\LocalizedController;
use Seo\Tests\Fixtures\NegotiateLocale;

final class LocalizedRoutesTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [EarlierFormatterProvider::class, EarlierListenerProvider::class, SeoServiceProvider::class];
    }

    public function test_the_default_keeps_its_uris_and_names_and_every_other_code_gets_a_prefixed_copy(): void
    {
        // Else the fixture's '/' keeps its early slot when the default copy overwrites it.
        Route::setRoutes(new RouteCollection);
        $locales = new Locales(['en', 'fr', 'zh-Hant'], 'en');
        $this->withLocalizedRoutes($locales->codes, static function (): void {
            Route::get('/', static fn () => 'home')->name('home');
            // A prefix group inside the closure is fine: it merges the marker in unchanged.
            Route::name('pages.')->prefix('legal')->middleware('throttle:60,1')->group(static function (): void {
                Route::get('terms', static fn () => 'terms')->name('terms');
            });
            Route::get('unnamed', static fn () => 'unnamed');
        });

        // Unnamed copies take the group's bare name; the default registers last so it never shadows a prefixed copy.
        $this->assertSame([
            'fr'                  => 'seo.fr.home',
            'fr/legal/terms'      => 'seo.fr.pages.terms',
            'fr/unnamed'          => 'seo.fr.',
            'zh-Hant'             => 'seo.zh-Hant.home',
            'zh-Hant/legal/terms' => 'seo.zh-Hant.pages.terms',
            'zh-Hant/unnamed'     => 'seo.zh-Hant.',
            '/'                   => 'home',
            'legal/terms'         => 'pages.terms',
            'unnamed'             => null,
        ], $this->localizedRoutes());

        $route = Route::getRoutes()->getByName('seo.fr.pages.terms');
        $this->assertEquals(new LocalizedRoute($locales, 'fr'), LocalizedRoute::of($route));
        $this->assertEquals(new LocalizedRoute($locales, 'en'), LocalizedRoute::of(Route::getRoutes()->getByName('pages.terms')));
        $this->assertSame(['web', 'throttle:60,1'], $route->gatherMiddleware());
        $this->assertNull(LocalizedRoute::of(null));
    }

    /** @return iterable<string, array{Closure(): mixed}> */
    public static function misplacements(): iterable
    {
        yield 'inside a prefix group' => [static fn () => Route::prefix('app')->group(
            static fn () => Route::localized(static fn () => null),
        )];
        yield 'inside another Route::localized()' => [static fn () => Route::localized(
            static fn () => Route::localized(static fn () => null),
        )];
    }

    #[DataProvider('misplacements')]
    public function test_it_throws_where_the_locale_would_not_be_the_first_path_segment(Closure $register): void
    {
        config(['seo.locales' => ['en' => 'en', 'fr' => 'fr']]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Route::localized() cannot sit inside a prefix group or another Route::localized()');

        $register();
    }

    /** @return iterable<string, array{Closure(): mixed}> */
    public static function routeLevelPrefixes(): iterable
    {
        yield '->prefix() on the route' => [static fn () => Route::get('x', static fn () => '')->prefix('admin')];
        yield 'Route::prefix()->get()' => [static fn () => Route::prefix('admin')->get('x', static fn () => '')];
    }

    #[DataProvider('routeLevelPrefixes')]
    public function test_it_throws_when_a_route_level_prefix_pushes_the_locale_off_the_first_segment(Closure $routes): void
    {
        config(['seo.locales' => ['en' => 'en', 'fr' => 'fr']]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Route::localized(): [admin/fr/x] puts the locale after a route-level prefix');

        Route::localized($routes);
    }

    public function test_a_v0_2_call_passing_locales_says_how_to_upgrade(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("Route::localized() takes only the routes closure since v0.3: set the languages in config('seo.locales') as code => name, default first.");

        // @phpstan-ignore arguments.count (v0.2's call, on purpose)
        Route::localized(new Locales(['en', 'fr'], 'en'), static fn () => null);
    }

    public function test_below_two_locales_the_routes_register_once_as_plain_routes(): void
    {
        config(['seo.locales' => ['en' => 'English']]);

        Route::localized(static fn () => Route::get('only', static fn () => app()->getLocale())->name('only'));
        Route::getRoutes()->refreshNameLookups();

        $this->assertNull(LocalizedRoute::of(Route::getRoutes()->getByName('only')));
        $this->assertFalse(Route::has('seo.fr.only'));
        $this->get('/only')->assertOk()->assertContent('en');
    }

    public function test_a_path_that_is_not_the_copys_throws_instead_of_cutting_the_wrong_bytes(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('[/frx/terms] is not a path of the fr copy.');

        new LocalizedRoute(new Locales(['en', 'fr'], 'en'), 'fr')->path('/frx/terms', 'en');
    }

    public function test_the_default_copy_never_gets_a_path_a_browser_reads_as_another_host(): void
    {
        $fr = new LocalizedRoute(new Locales(['en', 'fr', 'ar'], 'en'), 'fr');

        $this->assertSame('/evil.test/x', $fr->path('/fr//evil.test/x', 'en'));
        $this->assertSame('/', $fr->path('/fr//', 'en'));
        $this->assertSame('/ar//evil.test/x', $fr->path('/fr//evil.test/x', 'ar'));
    }

    public function test_a_default_route_opening_with_a_parameter_never_catches_another_codes_urls(): void
    {
        $this->withSite();
        $this->withLocalizedRoutes(['en', 'fr'], static function (): void {
            Route::get('/', static fn () => 'home:' . app()->getLocale());
            Route::get('terms', static fn () => 'terms:' . app()->getLocale());
            Route::get('{page}', static fn (string $page) => "page:{$page}:" . app()->getLocale());
            Route::get('{category}/{post}', static fn (string $category, string $post) => "post:{$category}/{$post}:" . app()->getLocale());
            Route::fallback(static fn () => 'fallback:' . app()->getLocale());
        });

        foreach ([
            '/'         => 'home:en',
            '/fr'       => 'home:fr',
            '/fr/terms' => 'terms:fr',
            '/about'    => 'page:about:en',
            '/fr/about' => 'page:about:fr',
            '/a/b'      => 'post:a/b:en',
            '/fr/a/b'   => 'post:a/b:fr',
            '/a/b/c'    => 'fallback:en',
            '/fr/a/b/c' => 'fallback:fr',
        ] as $url => $content) {
            $this->get($url)->assertOk()->assertContent($content);
        }

        // The sitemap matches a loc the same way.
        $this->withSitemap(['/fr']);
        $this->assertSame(['http://localhost/', 'http://localhost/fr'], array_column(iterator_to_array($this->seo()->sitemap(), false), 'loc'));
    }

    public function test_bindings_resolve_under_the_urls_locale(): void
    {
        // The matched listener sets the locale before SubstituteBindings (in `web`) runs.
        $this->app->make(Kernel::class)->appendMiddlewareToGroup('web', NegotiateLocale::class);
        Route::bind('post', static fn (string $value): string => app()->getLocale() . ':' . $value);
        $this->withLocalizedRoutes(['en', 'fr'], static function (): void {
            Route::get('posts/{post}', static fn (string $post): string => $post);
        });

        foreach (['/fr/posts/x' => 'fr:x', '/posts/x' => 'en:x'] as $url => $content) {
            $this->withSession(['locale' => 'es'])->get($url)->assertOk()->assertContent($content);
        }
    }

    public function test_it_is_fine_inside_domain_name_and_middleware_groups(): void
    {
        $this->withSite();
        config(['seo.locales' => ['en' => 'en', 'fr' => 'fr']]);
        Route::domain('localhost')->name('site.')->middleware('web')->group(static function (): void {
            Route::localized(static function (): void {
                Route::get('terms', static fn () => app()->getLocale())->name('terms');
            });
        });
        Route::getRoutes()->refreshNameLookups();

        $this->assertSame(['fr/terms' => 'site.seo.fr.terms', 'terms' => 'site.terms'], $this->localizedRoutes());
        $this->assertSame('localhost', Route::getRoutes()->getByName('site.seo.fr.terms')->getDomain());
        $this->get('/fr/terms')->assertOk()->assertContent('fr');
        $this->get('http://go.test/fr/terms')->assertNotFound();
    }

    public function test_a_copy_receives_its_route_parameters_and_no_locale(): void
    {
        $this->withLocalizedRoutes(['en', 'fr'], static function (): void {
            Route::get('posts/{slug}', static fn (string $slug): string => json_encode(func_get_args(), JSON_THROW_ON_ERROR));
        });

        $this->get('/fr/posts/hello')->assertOk()->assertContent('["hello"]');
        $this->get('/posts/hello')->assertOk()->assertContent('["hello"]');
    }

    public function test_route_urls_follow_the_current_locale(): void
    {
        $this->withLocalizedRoutes(['en', 'fr', 'ar'], static function (): void {
            Route::get('/', static fn () => '')->name('home');
            Route::get('terms', static fn () => '')->name('terms');
        });
        Route::get('members', static fn () => '')->name('members');
        Route::getRoutes()->refreshNameLookups();

        $this->app->setLocale('en');
        $this->assertSame('http://localhost/terms', route('terms'));
        $this->assertSame('http://localhost', route('home'));
        $this->assertSame('http://localhost/terms', route('seo.fr.terms'));
        $this->assertSame('/terms?page=2', route('terms', ['page' => 2], false));

        $this->app->setLocale('fr');
        $this->assertSame('http://localhost/fr/terms', route('terms'));
        $this->assertSame('http://localhost/fr', route('home'));
        $this->assertSame('http://localhost/fr/terms', route('seo.ar.terms'));
        $this->assertSame('http://localhost/fr', route('seo.ar.home'));
        $this->assertSame('/fr/terms?page=2', route('terms', ['page' => 2], false));
        $this->assertStringStartsWith('http://localhost/fr/terms?signature=', URL::signedRoute('terms'));
        $this->assertStringStartsWith('http://localhost/fr/terms?expires=', URL::temporarySignedRoute('terms', 60));
        $this->assertSame('http://localhost/fr/terms', redirect()->route('terms')->getTargetUrl());
        $this->assertSame('http://localhost/members', route('members'));
        $this->assertSame('http://localhost/terms', url('/terms'));
        $this->assertSame('http://localhost/terms', URL::to('terms'));

        // Not one of the route's codes: the default's URL.
        $this->app->setLocale('de');
        $this->assertSame('http://localhost/terms', route('seo.ar.terms'));
        $this->assertSame('http://localhost', route('seo.fr.home'));
    }

    public function test_every_copy_answers_to_the_routes_own_name(): void
    {
        config(['seo.locales' => ['en' => 'en', 'fr' => 'fr']]);
        $answers = static fn (): array => [Route::currentRouteName(), Route::is('terms', 'site.about'), request()->routeIs('terms', 'site.about')];
        Route::name('site.')->middleware('web')->group(static fn () => Route::localized(static function () use ($answers): void {
            Route::get('about', $answers)->name('about');
            Route::get('contact', $answers);
        }));
        $this->withLocalizedRoutes(['en', 'fr'], static function () use ($answers): void {
            Route::get('terms', $answers)->name('terms');
            Route::get('unnamed', $answers);
        });

        foreach ([
            '/terms'      => ['terms', true, true],
            '/fr/terms'   => ['terms', true, true],
            '/about'      => ['site.about', true, true],
            '/fr/about'   => ['site.about', true, true],
            '/contact'    => ['site.', false, false],
            '/fr/contact' => ['site.', false, false],
            '/unnamed'    => [null, false, false],
            '/fr/unnamed' => [null, false, false],
        ] as $url => $answer) {
            // Twice: the uncached collection hands back the same Route, already renamed.
            $this->get($url)->assertOk()->assertExactJson($answer);
            $this->get($url)->assertOk()->assertExactJson($answer);
        }

        foreach (['en' => 'http://localhost', 'fr' => 'http://localhost/fr'] as $locale => $base) {
            $this->app->setLocale($locale);

            foreach (['terms' => '/terms', 'seo.fr.terms' => '/terms', 'site.about' => '/about', 'site.seo.fr.about' => '/about'] as $name => $path) {
                $this->assertSame($base . $path, route($name));
            }
        }
    }

    public function test_a_route_matched_listener_an_earlier_provider_set_sees_the_routes_own_name(): void
    {
        $this->withLocalizedRoutes(['en', 'fr'], static function (): void {
            Route::get('terms', static fn () => '')->name('terms');
        });

        $this->get('/fr/terms')->assertOk();

        $this->assertSame(['terms'], EarlierListenerProvider::$names);
    }

    public function test_action_urls_follow_the_current_locale_whichever_copy_the_lookup_keeps(): void
    {
        // The action lookup keeps the last copy (the default) on Laravel 12, the first on 13.
        $this->withLocalizedRoutes(['en', 'fr', 'ar'], static function (): void {
            Route::get('terms', [LocalizedController::class, 'terms'])->name('terms');
        });

        foreach (['en' => 'http://localhost/terms', 'fr' => 'http://localhost/fr/terms', 'ar' => 'http://localhost/ar/terms'] as $locale => $url) {
            $this->app->setLocale($locale);
            $this->assertSame($url, action([LocalizedController::class, 'terms']));
        }
    }

    public function test_a_formatter_an_earlier_provider_set_still_applies_after_the_packages(): void
    {
        $this->withLocalizedRoutes(['en', 'fr'], static function (): void {
            Route::get('legacy', static fn () => '')->name('legacy');
        });

        $this->app->setLocale('fr');
        $this->assertSame('http://localhost/fr/renamed', route('legacy'));
        $this->assertSame('http://localhost/renamed', url('/legacy'));
    }

    public function test_the_routes_survive_route_cache(): void
    {
        $this->defineCacheRoutes(<<<'PHP'
            <?php

            use Illuminate\Support\Facades\Route;
            use Seo\Tests\Fixtures\LocalizedController;

            // route:cache runs in a subprocess with a fresh config: the codes are set here.
            config(['seo.locales' => ['en' => 'en', 'fr' => 'fr', 'ar' => 'ar']]);

            // First: the default {category}/{post} below would catch /fr/about.
            Route::middleware('web')->name('site.')->group(static function (): void {
                Route::localized(static function (): void {
                    Route::get('about', static fn () => Route::currentRouteName())->name('about');
                });
            });

            Route::middleware('web')->group(static function (): void {
                Route::localized(static function (): void {
                    Route::get('terms', [LocalizedController::class, 'terms'])->name('terms');
                    // A default route opening with a parameter must not catch /ar/terms under the compiled matcher either.
                    Route::get('{category}/{post}', static fn () => 'post');
                    // Two unnamed routes: each copy names both after its group, and route:cache must not call that a clash.
                    Route::get('one', static fn () => Route::currentRouteName());
                    Route::get('two', static fn () => 'two');
                });
            });
            PHP);

        $this->assertTrue($this->app->routesAreCached());
        $this->assertEquals(
            new LocalizedRoute(new Locales(['en', 'fr', 'ar'], 'en'), 'ar'),
            LocalizedRoute::of(Route::getRoutes()->getByName('seo.ar.terms')),
        );

        $this->get('/ar/two')->assertOk()->assertContent('two');
        $this->get('/fr/about')->assertOk()->assertContent('site.about');

        // route:cache names every unnamed route: the copy's generated name drops seo.ar. as the default's never had it.
        foreach (['/one', '/ar/one'] as $path) {
            $this->assertStringStartsWith('generated::', (string)$this->get($path)->assertOk()->getContent());
        }

        foreach (['/terms' => 'en', '/fr/terms' => 'fr', '/ar/terms' => 'ar'] as $path => $locale) {
            $json = [
                'locale'  => $locale,
                'route'   => "http://localhost{$path}",
                'action'  => "http://localhost{$path}",
                'name'    => 'terms',
                'is'      => true,
                'routeIs' => true,
            ];
            // Twice: the compiled collection keeps the Route it built per name, already renamed.
            $this->get($path)->assertOk()->assertExactJson($json);
            $this->get($path)->assertOk()->assertExactJson($json);
        }

        $this->assertSame('http://localhost/ar/terms', route('seo.fr.terms'));
        $this->app->setLocale('en');
        $this->assertSame('http://localhost/terms', route('terms'));
        $this->assertSame('http://localhost/terms', route('seo.ar.terms'));
    }

    /** @return array<string, string|null> uri => name, in registration order */
    private function localizedRoutes(): array
    {
        $routes = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            /** @var RoutingRoute $route */
            if (LocalizedRoute::of($route) !== null) {
                $routes[$route->uri()] = $route->getName();
            }
        }

        return $routes;
    }
}
