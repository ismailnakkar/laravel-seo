<?php

declare(strict_types=1);

namespace Seo\Tests;

use Closure;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Events\LocaleUpdated;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Seo\Http\SetLocale;
use Seo\Locales;
use Seo\LocalizedRoute;
use Seo\SeoServiceProvider;
use Seo\Tests\Fixtures\EarlierFormatterProvider;
use Seo\Tests\Fixtures\LocalizedController;
use Seo\Tests\Fixtures\NegotiateLocale;

final class LocalizedRoutesTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [EarlierFormatterProvider::class, SeoServiceProvider::class];
    }

    public function test_the_default_keeps_its_uris_and_names_and_every_other_code_gets_a_prefixed_copy(): void
    {
        // Else the fixture's '/' keeps its early slot when the default copy overwrites it.
        Route::setRoutes(new RouteCollection);
        $locales = new Locales(['en', 'fr', 'zh-Hant'], 'en');
        $this->withLocalizedRoutes($locales, static function (): void {
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
        $this->assertSame(['web', SetLocale::class, 'throttle:60,1'], $route->gatherMiddleware());
        $this->assertNull(LocalizedRoute::of(null));
    }

    /** @return iterable<string, array{Closure(Locales): mixed}> */
    public static function misplacements(): iterable
    {
        yield 'inside a prefix group' => [static fn (Locales $locales) => Route::prefix('app')->group(
            static fn () => Route::localized($locales, static fn () => null),
        )];
        yield 'inside another Route::localized()' => [static fn (Locales $locales) => Route::localized(
            $locales,
            static fn () => Route::localized($locales, static fn () => null),
        )];
    }

    #[DataProvider('misplacements')]
    public function test_it_throws_where_the_locale_would_not_be_the_first_path_segment(Closure $register): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Route::localized() cannot sit inside a prefix group or another Route::localized()');

        $register(new Locales(['en', 'fr'], 'en'));
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
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Route::localized(): [admin/fr/x] puts the locale after a route-level prefix');

        Route::localized(new Locales(['en', 'fr'], 'en'), $routes);
    }

    public function test_a_path_that_is_not_the_copys_throws_instead_of_cutting_the_wrong_bytes(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('[/frx/terms] is not a path of the fr copy.');

        new LocalizedRoute(new Locales(['en', 'fr'], 'en'), 'fr')->path('/frx/terms', 'en');
    }

    public function test_a_default_route_opening_with_a_parameter_never_catches_another_codes_urls(): void
    {
        $this->withSite();
        $this->withLocalizedRoutes(new Locales(['en', 'fr'], 'en'), static function (): void {
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
        // SubstituteBindings sits in `web`, ahead of SetLocale; the app's own locale middleware runs after it.
        $this->app->make(Kernel::class)->appendMiddlewareToGroup('web', NegotiateLocale::class);
        Route::bind('post', static fn (string $value): string => app()->getLocale() . ':' . $value);
        $this->withLocalizedRoutes(new Locales(['en', 'fr'], 'en'), static function (): void {
            Route::get('posts/{post}', static fn (string $post): string => $post);
        });

        foreach (['/fr/posts/x' => 'fr:x', '/posts/x' => 'en:x'] as $url => $content) {
            $this->withSession(['locale' => 'es'])->get($url)->assertOk()->assertContent($content);
        }
    }

    public function test_it_is_fine_inside_domain_name_and_middleware_groups(): void
    {
        $this->withSite();
        Route::domain('localhost')->name('site.')->middleware('web')->group(static function (): void {
            Route::localized(new Locales(['en', 'fr'], 'en'), static function (): void {
                Route::get('terms', static fn () => app()->getLocale())->name('terms');
            });
        });
        Route::getRoutes()->refreshNameLookups();

        $this->assertSame(['fr/terms' => 'site.seo.fr.terms', 'terms' => 'site.terms'], $this->localizedRoutes());
        $this->assertSame('localhost', Route::getRoutes()->getByName('site.seo.fr.terms')->getDomain());
        $this->get('/fr/terms')->assertOk()->assertContent('fr');
        $this->get('http://go.test/fr/terms')->assertNotFound();
    }

    public function test_the_url_names_the_locale_whatever_the_session_or_accept_language_say(): void
    {
        Event::fake([LocaleUpdated::class]);
        // The app's own locale middleware sits in `web`; SetLocale is gathered after the group, so it has the last word.
        $this->app->make(Kernel::class)->appendMiddlewareToGroup('web', NegotiateLocale::class);
        $this->withLocalizedRoutes(new Locales(['en', 'fr', 'ar', 'es'], 'en'), static function (): void {
            Route::get('terms', static fn () => app()->getLocale());
        });

        foreach (['/fr/terms' => 'fr', '/terms' => 'en'] as $url => $locale) {
            $this->withSession(['locale' => 'es'])
                ->get($url, ['Accept-Language' => 'ar'])
                ->assertOk()
                ->assertContent($locale);

            Event::assertDispatched(LocaleUpdated::class, static fn (LocaleUpdated $event): bool => $event->locale === $locale);
        }

        Event::assertDispatched(LocaleUpdated::class, static fn (LocaleUpdated $event): bool => $event->locale === 'es');
    }

    public function test_a_copy_receives_its_route_parameters_and_no_locale(): void
    {
        $this->withLocalizedRoutes(new Locales(['en', 'fr'], 'en'), static function (): void {
            Route::get('posts/{slug}', static fn (string $slug): string => json_encode(func_get_args(), JSON_THROW_ON_ERROR));
        });

        $this->get('/fr/posts/hello')->assertOk()->assertContent('["hello"]');
        $this->get('/posts/hello')->assertOk()->assertContent('["hello"]');
    }

    public function test_route_urls_follow_the_current_locale(): void
    {
        $this->withLocalizedRoutes(new Locales(['en', 'fr', 'ar'], 'en'), static function (): void {
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

    public function test_action_urls_follow_the_current_locale_whichever_copy_the_lookup_keeps(): void
    {
        // The action lookup keeps the last copy (the default) on Laravel 12, the first on 13.
        $this->withLocalizedRoutes(new Locales(['fr', 'en', 'ar'], 'en'), static function (): void {
            Route::get('terms', [LocalizedController::class, 'terms'])->name('terms');
        });

        foreach (['en' => 'http://localhost/terms', 'fr' => 'http://localhost/fr/terms', 'ar' => 'http://localhost/ar/terms'] as $locale => $url) {
            $this->app->setLocale($locale);
            $this->assertSame($url, action([LocalizedController::class, 'terms']));
        }
    }

    public function test_a_formatter_an_earlier_provider_set_still_applies_after_the_packages(): void
    {
        $this->withLocalizedRoutes(new Locales(['en', 'fr'], 'en'), static function (): void {
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
            use Seo\Locales;
            use Seo\Tests\Fixtures\LocalizedController;

            Route::middleware('web')->group(static function (): void {
                Route::localized(new Locales(['fr', 'en', 'ar'], 'en'), static function (): void {
                    Route::get('terms', [LocalizedController::class, 'terms'])->name('terms');
                    // A default route opening with a parameter must not catch /ar/terms under the compiled matcher either.
                    Route::get('{category}/{post}', static fn () => 'post');
                    // Two unnamed routes: each copy names both after its group, and route:cache must not call that a clash.
                    Route::get('one', static fn () => 'one');
                    Route::get('two', static fn () => 'two');
                });
            });
            PHP);

        $this->assertTrue($this->app->routesAreCached());
        $this->assertEquals(
            new LocalizedRoute(new Locales(['fr', 'en', 'ar'], 'en'), 'ar'),
            LocalizedRoute::of(Route::getRoutes()->getByName('seo.ar.terms')),
        );

        $this->get('/ar/two')->assertOk()->assertContent('two');

        foreach (['/terms' => 'en', '/fr/terms' => 'fr', '/ar/terms' => 'ar'] as $path => $locale) {
            $this->get($path)->assertOk()->assertExactJson([
                'locale' => $locale,
                'route'  => "http://localhost{$path}",
                'action' => "http://localhost{$path}",
            ]);
        }
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
