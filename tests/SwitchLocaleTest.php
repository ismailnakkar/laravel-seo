<?php

declare(strict_types=1);

namespace Seo\Tests;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Seo\Http\ResolveLocale;
use Seo\Language;
use Seo\Tests\Fixtures\User;

final class SwitchLocaleTest extends LanguagesTestCase
{
    protected function defineWebRoutes($router): void
    {
        $locale = static fn (): string => app()->getLocale();

        $router->get('plain', $locale);
        $router->get('plain-signed/{user}', $locale)->middleware('signed')->name('plain.signed');
        $router->localized(static function (Router $router) use ($locale): void {
            $router->get('/', $locale)->name('home');
            $router->get('terms', $locale)->name('terms');
            $router->get('prefs/{user}', $locale)->middleware('signed')->name('prefs');
            $router->get('prefs-unnamed/{user}', $locale)->middleware('signed');
        });
        $router->domain('app.test')->group(static fn (Router $router) => $router->localized(
            static fn (Router $router) => $router->get('dashboard', $locale)->name('dashboard'),
        ));
    }

    private static function pathAndQuery(string $url): string
    {
        $parts = (array)parse_url($url);

        return ($parts['path'] ?? '/') . (isset($parts['query']) ? "?{$parts['query']}" : '');
    }

    public function test_it_needs_the_csrf_token(): void
    {
        // Unit tests skip CSRF. offsetSet(), not `$this->app['env'] =`, which Larastan misreads.
        $this->app->offsetSet('env', 'production');

        $this->post('/locale', ['locale' => 'fr', 'to' => '/terms'])->assertStatus(419);

        $this->assertNotSame('fr', session(ResolveLocale::SESSION_KEY));
    }

    public function test_an_unknown_language_is_refused(): void
    {
        $this->from('/terms')->post('/locale', ['locale' => 'de', 'to' => '/terms'])
            ->assertRedirect('/terms')
            ->assertSessionHasErrors('locale');
    }

    public function test_the_choice_is_saved_to_the_session_and_the_account(): void
    {
        $user = User::create(['name' => 'member', 'locale' => 'en']);

        $this->actingAs($user)->post('/locale', ['locale' => 'fr', 'to' => '/plain'])->assertStatus(303)->assertRedirect('/plain');

        $this->assertSame('fr', session(ResolveLocale::SESSION_KEY));
        $this->assertSame('fr', $user->fresh()?->locale);
        $this->get('/plain')->assertContent('fr');
    }

    public function test_the_account_is_saved_through_the_closure(): void
    {
        $user = User::create(['name' => 'member', 'locale' => 'en']);
        $calls = [];
        $this->seo()->saveUserLocaleUsing(static function (User $model, string $code) use (&$calls): void {
            $calls[] = [$model, $code];
        });

        $this->actingAs($user)->post('/locale', ['locale' => 'fr', 'to' => '/plain'])->assertStatus(303);

        $this->assertSame([[$user, 'fr']], $calls);
        $this->assertSame('en', $user->fresh()?->locale);
        $this->assertSame('fr', $user->locale);
    }

    /** @return iterable<string, array{string, string, string}> to, locale, Location */
    public static function copies(): iterable
    {
        yield 'to its French copy, query kept' => ['/terms?page=2&utm_source=x', 'fr', 'http://localhost/fr/terms?page=2&utm_source=x'];
        yield 'back to the default' => ['/fr/terms', 'en', 'http://localhost/terms'];
        yield 'the French home to the default' => ['/fr', 'en', 'http://localhost'];
        yield 'between two prefixed copies' => ['/es/terms', 'ar', 'http://localhost/ar/terms'];
        yield 'an encoded prefix' => ['/%66r/terms', 'ar', 'http://localhost/ar/terms'];
        yield 'the language already on screen' => ['/es/terms?x=1', 'es', 'http://localhost/es/terms?x=1'];
        yield 'a page outside Route::localized()' => ['/plain?x=1', 'fr', 'http://localhost/plain?x=1'];
        yield 'an unknown page' => ['/nope', 'fr', 'http://localhost/nope'];
    }

    #[DataProvider('copies')]
    public function test_it_returns_to_the_page_in_the_chosen_language(string $to, string $locale, string $location): void
    {
        $this->post('/locale', ['locale' => $locale, 'to' => $to])->assertStatus(303)->assertHeader('Location', $location);
    }

    public function test_a_domain_group_route_matches_on_its_host(): void
    {
        $this->post('http://app.test/locale', ['locale' => 'fr', 'to' => '/dashboard'])
            ->assertHeader('Location', 'http://app.test/fr/dashboard');
    }

    public function test_it_answers_on_whichever_host_the_form_sat(): void
    {
        $this->post('http://go.test/locale', ['locale' => 'fr', 'to' => '/plain'])->assertHeader('Location', 'http://go.test/plain');
    }

    /** @return iterable<string, array{mixed}> */
    public static function refusedTargets(): iterable
    {
        yield 'protocol-relative' => ['//evil.test'];
        yield 'a backslash' => ['/\\evil.test'];
        yield 'two backslashes' => ['\\\\evil.test'];
        yield 'absolute' => ['https://evil.test'];
        yield 'a tab' => ["/\t/evil.test"];
        yield 'a newline' => ["/\n/evil.test"];
        yield 'a carriage return' => ["/\r/evil.test"];
        yield 'NUL' => ["/\0/evil.test"];
        yield 'a space' => ['/ /evil.test'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'empty' => [''];
        yield 'missing' => [null];
        yield 'an array' => [['/terms']];
    }

    #[DataProvider('refusedTargets')]
    public function test_a_target_off_this_host_goes_home(mixed $to): void
    {
        $this->post('/locale', ['locale' => 'fr', ...($to === null ? [] : ['to' => $to])])->assertStatus(303)->assertRedirect('/');
    }

    /** @return iterable<string, array{string}> */
    public static function onSiteTargets(): iterable
    {
        yield 'encoded slashes' => ['/%2F%2Fevil.test'];
        yield 'an encoded backslash' => ['/%5Cevil.test'];
        yield 'a catch-all copy leaving a double slash' => ['/fr//evil.test/x'];
        yield 'the same, prefix encoded' => ['/%66r//evil.test'];
        yield 'the French home with a slash too many' => ['/fr//'];
        yield 'a malformed escape' => ['/%'];
        yield 'a lone question mark' => ['/?'];
        yield 'very long' => ['/' . str_repeat('a', 5000)];
    }

    #[DataProvider('onSiteTargets')]
    public function test_a_target_never_leaves_this_host(string $to): void
    {
        Route::localized(static fn () => Route::get('{page}', static fn (string $page) => $page)->where('page', '.*'));
        Route::getRoutes()->refreshNameLookups();

        $location = (string)$this->post('/locale', ['locale' => 'en', 'to' => $to])->assertStatus(303)->headers->get('Location');

        $this->assertSame('localhost', parse_url($location, PHP_URL_HOST), $location);
    }

    public function test_a_valid_signed_page_is_signed_again_for_the_chosen_copy_with_its_expiry(): void
    {
        $expires = now()->addHour()->startOfSecond();
        $to = self::pathAndQuery(URL::temporarySignedRoute('prefs', $expires, ['user' => 5]));

        $location = (string)$this->post('/locale', ['locale' => 'ar', 'to' => $to])->assertStatus(303)->headers->get('Location');

        $this->assertStringStartsWith('http://localhost/ar/prefs/5?', $location);
        parse_str((string)parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame((string)$expires->getTimestamp(), $query['expires'] ?? null);
        $this->get($location)->assertOk()->assertContent('ar');
    }

    /** @return iterable<string, array{Closure(): string}> the signed URL, built once the app runs */
    public static function unsignable(): iterable
    {
        yield 'tampered' => [static fn (): string => URL::signedRoute('prefs', ['user' => 5]) . 'x'];
        yield 'expired' => [static fn (): string => URL::temporarySignedRoute('prefs', now()->subMinute(), ['user' => 5])];
        yield 'a parameter added after signing' => [static fn (): string => URL::signedRoute('prefs', ['user' => 5]) . '&lang=fr'];
        yield 'unnamed' => [static fn (): string => 'http://localhost/prefs-unnamed/5?signature=' . hash_hmac('sha256', 'http://localhost/prefs-unnamed/5', (string)config('app.key'))];
        yield 'not localized' => [static fn (): string => URL::signedRoute('plain.signed', ['user' => 5])];
    }

    #[DataProvider('unsignable')]
    public function test_a_page_that_cannot_be_signed_again_comes_back_unchanged(Closure $url): void
    {
        $to = self::pathAndQuery($url());

        $this->post('/locale', ['locale' => 'ar', 'to' => $to])->assertStatus(303)->assertHeader('Location', "http://localhost{$to}");
    }

    public function test_an_unsigned_url_to_a_signed_route_is_never_signed(): void
    {
        $this->post('/locale', ['locale' => 'ar', 'to' => '/prefs/5'])->assertHeader('Location', 'http://localhost/ar/prefs/5');

        $this->get('/ar/prefs/5')->assertForbidden();
    }

    /** 'prefs' has no domain, so the landing checks all pass on other.test: only the host comparison catches this. */
    public function test_a_forced_root_url_never_signs_for_that_host(): void
    {
        $to = self::pathAndQuery(URL::signedRoute('prefs', ['user' => 5]));

        URL::forceRootUrl('http://other.test');

        $location = (string)$this->post('/locale', ['locale' => 'ar', 'to' => $to])->assertStatus(303)->headers->get('Location');

        $this->assertSame($to, self::pathAndQuery($location));
    }

    /** Stripping seo.en. from the default copy's own name would sign the unrelated `admin` route. */
    public function test_a_default_copy_name_that_looks_prefixed_never_signs_an_unrelated_route(): void
    {
        $locale = static fn (): string => app()->getLocale();

        Route::localized(static fn () => Route::get('odd/{user}', $locale)->middleware('signed')->name('seo.en.admin'));
        Route::get('admin-x/{user}', $locale)->middleware('signed')->name('admin');
        Route::getRoutes()->refreshNameLookups();

        $to = self::pathAndQuery(URL::signedRoute('seo.en.admin', ['user' => 5]));

        $location = (string)$this->post('/locale', ['locale' => 'ar', 'to' => $to])->assertStatus(303)->headers->get('Location');

        $this->assertStringStartsWith('http://localhost/ar/odd/5?', $location);
        $this->get($location)->assertOk()->assertContent('ar');
    }

    public function test_a_duplicate_route_name_never_signs_a_different_route(): void
    {
        $locale = static fn (): string => app()->getLocale();

        Route::get('other/{user}', $locale)->name('dup');
        Route::localized(static fn () => Route::get('dup/{user}', $locale)->middleware('signed')->name('dup'));
        Route::getRoutes()->refreshNameLookups();

        $to = self::pathAndQuery(URL::signedRoute('seo.fr.dup', ['user' => 5]));

        $this->post('/locale', ['locale' => 'fr', 'to' => $to])->assertStatus(303)->assertHeader('Location', "http://localhost{$to}");
    }

    public function test_a_route_holding_the_name_with_other_parameters_leaves_the_page_unchanged(): void
    {
        // Registered first, so it holds the name: lookups keep the first route per name.
        Route::get('elsewhere/{slug}', static fn () => 'plain')->name('renamed');
        Route::localized(static fn () => Route::get('renamed/{user}', static fn (): string => app()->getLocale())->middleware('signed')->name('renamed'));
        Route::getRoutes()->refreshNameLookups();

        $to = self::pathAndQuery(URL::signedRoute('seo.fr.renamed', ['user' => 5]));
        $this->get($to)->assertOk()->assertContent('en');

        $this->post('/locale', ['locale' => 'ar', 'to' => $to])->assertStatus(303)->assertHeader('Location', "http://localhost{$to}");
    }

    /** ->defaults() values merge into Route::parameters(), but the signed URL never carried them. */
    public function test_a_route_default_is_excluded_from_the_re_signed_query(): void
    {
        $locale = static fn (): string => app()->getLocale();

        Route::localized(static fn () => Route::get('withdef/{user}', $locale)->defaults('tab', 'general')->middleware('signed')->name('withdef'));
        Route::getRoutes()->refreshNameLookups();

        $to = self::pathAndQuery(URL::signedRoute('withdef', ['user' => 5]));

        $location = (string)$this->post('/locale', ['locale' => 'ar', 'to' => $to])->assertStatus(303)->headers->get('Location');
        parse_str((string)parse_url($location, PHP_URL_QUERY), $query);

        $this->assertArrayNotHasKey('tab', $query);
        $this->get($location)->assertOk();
    }

    /** A previous key validates a GET (rotation grace) but never renews a signature past it. */
    public function test_a_url_signed_under_a_retired_key_is_never_renewed(): void
    {
        $locale = static fn (): string => app()->getLocale();

        Route::localized(static fn () => Route::get('rotated/{user}', $locale)->middleware('signed')->name('rotated'));
        Route::getRoutes()->refreshNameLookups();

        $currentKey = (string)config('app.key');
        $oldKey = 'base64:' . base64_encode(str_repeat('o', 32));

        config(['app.key' => $oldKey]);
        $to = self::pathAndQuery(URL::signedRoute('rotated', ['user' => 5]));
        config(['app.key' => $currentKey, 'app.previous_keys' => [$oldKey]]);

        $this->get($to)->assertOk();

        $this->post('/locale', ['locale' => 'ar', 'to' => $to])->assertStatus(303)->assertHeader('Location', "http://localhost{$to}");
    }

    /** Signing borrows the app locale; the request keeps its own. */
    public function test_the_apps_locale_is_restored_after_a_re_sign(): void
    {
        $locale = static fn (): string => app()->getLocale();

        Route::localized(static fn () => Route::get('lockedloc/{user}', $locale)->middleware('signed')->name('lockedloc'));
        Route::getRoutes()->refreshNameLookups();

        $to = self::pathAndQuery(URL::signedRoute('lockedloc', ['user' => 5]));

        // A prior choice, so ResolveLocale sets the app locale to 'es' before the controller (and signedAgain()) runs.
        $this->withSession([ResolveLocale::SESSION_KEY => 'es']);

        $location = (string)$this->post('/locale', ['locale' => 'ar', 'to' => $to])->assertStatus(303)->headers->get('Location');

        // The re-sign ran: an early return would never touch the locale and pass vacuously.
        $this->assertStringStartsWith('http://localhost/ar/lockedloc/5?', $location);
        $this->assertSame('es', $this->app->getLocale());
    }

    public function test_a_domain_route_sharing_the_localized_name_never_signs_for_that_host(): void
    {
        $locale = static fn (): string => app()->getLocale();

        // admin.test first: Laravel 12 gives a shared name to the first route registered, 13 to a domain route.
        Route::domain('admin.test')->get('ar/dup/{user}', static fn () => 'ADMIN')->middleware('signed')->name('dup');
        Route::localized(static fn () => Route::get('dup/{user}', $locale)->middleware('signed')->name('dup'));
        Route::getRoutes()->refreshNameLookups();

        $to = self::pathAndQuery(URL::signedRoute('seo.fr.dup', ['user' => 5]));

        $this->post('/locale', ['locale' => 'ar', 'to' => $to])->assertStatus(303)->assertHeader('Location', "http://localhost{$to}");
    }

    public function test_a_localized_domain_group_sharing_the_name_never_signs_for_that_host(): void
    {
        // admin.test first: see above.
        Route::domain('admin.test')->group(static fn () => Route::localized(
            static fn () => Route::get('dup/{user}', static fn () => 'ADMIN')->middleware('signed')->name('dup'),
        ));
        Route::localized(static fn () => Route::get('dup/{user}', static fn (): string => app()->getLocale())->middleware('signed')->name('dup'));
        Route::getRoutes()->refreshNameLookups();

        // By hand: signing by name would now pick admin.test's seo.fr.dup.
        $to = '/fr/dup/5?signature=' . hash_hmac('sha256', 'http://localhost/fr/dup/5', (string)config('app.key'));
        $this->get($to)->assertOk();

        $this->post('/locale', ['locale' => 'ar', 'to' => $to])->assertStatus(303)->assertHeader('Location', "http://localhost{$to}");
    }

    public function test_languages_lists_config_order_with_the_language_on_screen_current(): void
    {
        $this->get('/fr/terms');

        $this->assertEquals([
            new Language('en', 'English', false),
            new Language('fr', 'Français', true),
            new Language('ar', 'العربية', false),
            new Language('es', 'Español', false),
        ], $this->seo()->languages());
    }

    public function test_everything_holds_on_compiled_routes(): void
    {
        $router = $this->app->make(Router::class);
        $routes = $router->getRoutes();
        assert($routes instanceof RouteCollection);
        $router->setCompiledRoutes($routes->compile());

        $this->withSession([ResolveLocale::SESSION_KEY => 'fr'])->get('/plain')->assertContent('fr');
        $this->get('/es/terms')->assertContent('es');
        $this->post('/locale', ['locale' => 'ar', 'to' => '/terms?x=1'])->assertHeader('Location', 'http://localhost/ar/terms?x=1');

        $expires = now()->addHour()->startOfSecond();
        $to = self::pathAndQuery(URL::temporarySignedRoute('prefs', $expires, ['user' => 5]));
        $location = (string)$this->post('/locale', ['locale' => 'fr', 'to' => $to])->headers->get('Location');

        parse_str((string)parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame((string)$expires->getTimestamp(), $query['expires'] ?? null);
        $this->get($location)->assertOk()->assertContent('fr');
    }

    public function test_a_route_cache_generated_name_is_never_signed_again(): void
    {
        // A second unnamed route: route:cache names its copy seo.fr.generated::…, which strips to no route at all.
        Route::localized(static fn () => Route::get('prefs-unnamed-too/{user}', static fn (): string => app()->getLocale())->middleware('signed'));
        $router = $this->app->make(Router::class);
        $routes = $router->getRoutes();
        assert($routes instanceof RouteCollection);
        $router->setCompiledRoutes($routes->compile());

        $to = '/fr/prefs-unnamed-too/5?signature=' . hash_hmac('sha256', 'http://localhost/fr/prefs-unnamed-too/5', (string)config('app.key'));
        $this->assertStringContainsString('generated::', (string)$router->getRoutes()->match(Request::create($to))->getName());
        $this->get($to)->assertOk();

        $this->post('/locale', ['locale' => 'ar', 'to' => $to])->assertStatus(303)->assertHeader('Location', "http://localhost{$to}");
    }

    public function test_the_readme_switcher_renders(): void
    {
        $this->app->setLocale('fr');

        $html = Blade::render(<<<'BLADE'
            @inject('seo', \Seo\Seo::class)
            <form method="POST" action="{{ route('seo.locale') }}">
                @csrf
                <input type="hidden" name="to" value="/fr/terms">
                @foreach ($seo->languages() as $language)
                    <button name="locale" value="{{ $language->code }}" lang="{{ $language->code }}" @if ($language->current) aria-current="true" @endif>{{ $language->name }}</button>
                @endforeach
            </form>
            BLADE);

        $this->assertStringContainsString('action="http://localhost/locale"', $html);
        $this->assertMatchesRegularExpression('~value="fr" lang="fr"\s+aria-current="true"\s*>Français</button>~', $html);
        $this->assertMatchesRegularExpression('~value="ar" lang="ar"\s*>العربية</button>~', $html);
    }
}
