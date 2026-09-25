<?php

declare(strict_types=1);

namespace Seo\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Seo\Http\ResolveLocale;
use Seo\Tests\Fixtures\LocaleCode;
use Seo\Tests\Fixtures\User;

final class ResolveLocaleTest extends LanguagesTestCase
{
    protected function defineWebRoutes($router): void
    {
        $locale = static fn (): string => app()->getLocale();

        $router->localized(static function (Router $router) use ($locale): void {
            $router->match(['GET', 'HEAD', 'POST', 'QUERY'], '/', $locale)->name('home');
            $router->get('terms', $locale)->name('terms');
            $router->post('sign-up', static function (): string {
                Auth::login(User::create(['name' => 'new']));

                return app()->getLocale();
            });
        });
        $router->get('plain', $locale);
        $router->get('limited', $locale)->middleware('throttle:1,1');
        // Like cuty's login-as: the admin's session, the member for the rest of the request. Route::middleware()
        // only accepts middleware names, never a raw Closure (it casts every entry to string), so the swap happens
        // in the route's own action instead; ResolveLocale, in the `web` group, has already run by then either way.
        $router->get('as/{member}', static function (Request $request) use ($locale): string {
            Auth::onceUsingId((int)$request->route('member'));

            return $locale();
        });
        // Its XSRF cookie also reads the session, so it must go too: excluding just StartSession leaves it crashing.
        // Both names: the `web` group carries VerifyCsrfToken on Laravel 12, PreventRequestForgery from 13 on.
        $router->get('sessionless', $locale)->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, VerifyCsrfToken::class, PreventRequestForgery::class]);
    }

    public function test_it_runs_in_web_straight_after_the_session(): void
    {
        $kernel = $this->app->make(Kernel::class);
        assert($kernel instanceof HttpKernel);
        $priority = $kernel->getMiddlewarePriority();

        $this->assertContains(ResolveLocale::class, $kernel->getMiddlewareGroups()['web']);
        // Larastan can't see getMiddlewarePriority() as a list (Laravel's own @return is bare array), so array_search()
        // types as int|string|false; the +1 needs the int cast to satisfy it.
        $this->assertSame((int)array_search(StartSession::class, $priority, true) + 1, array_search(ResolveLocale::class, $priority, true));
    }

    public function test_a_copy_renders_its_own_language_and_visiting_it_never_changes_the_choice(): void
    {
        $this->withSession([ResolveLocale::SESSION_KEY => 'fr']);

        $this->get('/es/terms')->assertContent('es');
        $this->get('/terms')->assertContent('en');
        $this->get('/plain')->assertContent('fr');
    }

    public function test_the_account_beats_the_session(): void
    {
        $user = User::create(['name' => 'member', 'locale' => 'ar']);

        $this->actingAs($user)->withSession([ResolveLocale::SESSION_KEY => 'fr'])->get('/plain')->assertContent('ar');
    }

    public function test_an_enum_cast_account_language_counts(): void
    {
        $user = User::create(['name' => 'member', 'locale' => 'fr']);
        $user->mergeCasts(['locale' => LocaleCode::class]);

        $this->actingAs($user)->get('/plain')->assertContent('fr');
    }

    public function test_the_session_beats_the_browser(): void
    {
        $this->withSession([ResolveLocale::SESSION_KEY => 'fr'])->withHeaders(['Accept-Language' => 'es'])->get('/plain')->assertContent('fr');
    }

    public function test_a_sessions_first_prefixed_page_sets_the_choice(): void
    {
        $this->withHeaders(['Accept-Language' => 'fr'])->get('/es/terms')->assertContent('es');

        $this->get('/plain')->assertContent('es');
    }

    public function test_the_browser_then_the_default(): void
    {
        $this->withHeaders(['Accept-Language' => 'fr-CA,en;q=0.5'])->get('/plain')->assertContent('fr');

        $this->flushSession();
        $this->withHeaders(['Accept-Language' => ''])->get('/plain')->assertContent('en');
    }

    public function test_a_saved_code_no_longer_configured_falls_through(): void
    {
        $this->withSession([ResolveLocale::SESSION_KEY => 'de'])->withHeaders(['Accept-Language' => 'es'])->get('/plain')->assertContent('es');
    }

    public function test_an_account_without_a_language_takes_the_choice_not_the_pages(): void
    {
        $user = User::create(['name' => 'member']);

        $this->actingAs($user)->withSession([ResolveLocale::SESSION_KEY => 'es'])->get('/fr/terms')->assertContent('fr');

        $this->assertSame('es', $user->fresh()?->locale);
    }

    public function test_an_account_language_is_never_overwritten(): void
    {
        $user = User::create(['name' => 'member', 'locale' => 'ar']);

        $this->actingAs($user)->withSession([ResolveLocale::SESSION_KEY => 'fr'])->get('/es/terms');

        $this->assertSame('ar', $user->fresh()?->locale);
    }

    public function test_signing_up_saves_the_pages_language(): void
    {
        $this->withSession([ResolveLocale::SESSION_KEY => 'es'])->post('/fr/sign-up')->assertContent('fr');

        $this->assertSame('fr', User::query()->where('name', 'new')->value('locale'));
    }

    public function test_an_admin_signed_in_as_a_member_never_writes_the_members_account(): void
    {
        $admin = User::create(['name' => 'admin', 'locale' => 'ar']);
        $member = User::create(['name' => 'member']);

        $this->actingAs($admin)->get("/as/{$member->id}")->assertOk();

        $this->assertNull($member->fresh()?->locale);
        $this->assertSame('ar', $admin->fresh()?->locale);
    }

    public function test_a_failed_write_after_the_page_is_reported_and_the_page_still_served(): void
    {
        Exceptions::fake();
        Event::listen('eloquent.saving: ' . User::class, static function (User $user): void {
            if ($user->isDirty('locale')) {
                throw new RuntimeException('database down');
            }
        });

        $this->post('/fr/sign-up')->assertOk()->assertContent('fr');

        Exceptions::assertReported(static fn (RuntimeException $e): bool => $e->getMessage() === 'database down');
    }

    public function test_a_failed_write_before_the_page_is_reported_and_the_page_still_served(): void
    {
        $user = User::create(['name' => 'member']);
        Exceptions::fake();
        Event::listen('eloquent.saving: ' . User::class, static fn () => throw new RuntimeException('database down'));

        $this->actingAs($user)->withHeaders(['Accept-Language' => 'fr'])->get('/plain')->assertOk()->assertContent('fr');

        Exceptions::assertReported(static fn (RuntimeException $e): bool => $e->getMessage() === 'database down');
    }

    public function test_a_refusal_ahead_of_the_route_speaks_the_visitors_language(): void
    {
        $this->withSession([ResolveLocale::SESSION_KEY => 'fr'])->get('/limited')->assertOk();
        $this->app->setLocale('en');

        $this->get('/limited')->assertStatus(429);

        $this->assertSame('fr', $this->app->getLocale());
    }

    public function test_a_route_without_a_session_still_resolves(): void
    {
        $this->withHeaders(['Accept-Language' => 'es'])->get('/sessionless')->assertOk()->assertContent('es');
    }

    public function test_a_signed_in_user_that_is_not_an_eloquent_model_is_left_alone(): void
    {
        $this->actingAs(new GenericUser(['id' => 1]))->withHeaders(['Accept-Language' => 'fr'])->get('/plain')->assertOk()->assertContent('fr');
    }

    public function test_carbon_follows_the_page(): void
    {
        $this->get('/fr/terms');

        $this->assertSame('fr', Carbon::getLocale());
    }

    public function test_an_arrival_from_outside_goes_to_its_languages_copy_with_the_query_untouched(): void
    {
        $this->withHeaders(['Accept-Language' => 'fr'])->get('/?utm_source=x&b=2&a=1')
            ->assertStatus(302)
            ->assertHeader('Location', 'http://localhost/fr?utm_source=x&b=2&a=1');

        $this->flushSession();
        // head(), not call('HEAD', ...): call() never folds withHeaders() into the request, only the per-verb wrappers do.
        $this->withHeaders(['Accept-Language' => 'fr'])->head('/')->assertStatus(302);
    }

    public function test_a_saved_member_typing_the_domain_lands_on_their_language(): void
    {
        $this->actingAs(User::create(['name' => 'member', 'locale' => 'ar']))->get('/')->assertRedirect('/ar');
    }

    public function test_the_redirect_is_opt_in_per_route(): void
    {
        config(['seo.entry_redirect' => ['terms']]);

        $this->withHeaders(['Accept-Language' => 'fr'])->get('/terms')->assertRedirect('/fr/terms');
        $this->get('/')->assertOk()->assertContent('en');
    }

    /** @return iterable<string, array{string, string, array<string, string>}> method, URI, headers */
    public static function staysPut(): iterable
    {
        yield 'a route not listed' => ['GET', '/terms', ['Accept-Language' => 'fr']];
        yield 'a POST' => ['POST', '/', ['Accept-Language' => 'fr']];
        yield 'a QUERY, which Symfony 7.4+ counts as cacheable' => ['QUERY', '/', ['Accept-Language' => 'fr']];
        yield 'a signed URL' => ['GET', '/?signature=x', ['Accept-Language' => 'fr']];
        yield 'a click inside the site' => ['GET', '/', ['Accept-Language' => 'fr', 'Sec-Fetch-Site' => 'same-origin']];
        yield 'a click inside the site, by Referer' => ['GET', '/', ['Accept-Language' => 'fr', 'Referer' => 'http://localhost/fr/terms']];
        yield 'Googlebot sending a language' => ['GET', '/', ['Accept-Language' => 'fr', 'User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)']];
        yield 'the default language' => ['GET', '/', ['Accept-Language' => 'en']];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('staysPut')]
    public function test_it_stays_put(string $method, string $uri, array $headers): void
    {
        // call() never folds withHeaders() in (only get()/post()/head()/… do), and $method is dynamic here, so the
        // server vars are built directly.
        $this->call($method, $uri, server: $this->transformHeadersToServerVars($headers))->assertOk()->assertContent('en');
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function arrivals(): iterable
    {
        yield 'from another site' => [['Sec-Fetch-Site' => 'cross-site']];
        yield 'typed or bookmarked' => [['Sec-Fetch-Site' => 'none']];
        yield 'no Fetch Metadata, a search engine as Referer' => [['Referer' => 'https://www.google.com/']];
        yield 'an empty user agent, which is no crawler' => [['User-Agent' => '']];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('arrivals')]
    public function test_an_arrival_is_redirected(array $headers): void
    {
        $this->withHeaders(['Accept-Language' => 'fr', ...$headers])->get('/')->assertRedirect('/fr');
    }

    public function test_a_prefixed_copy_is_never_redirected(): void
    {
        $this->withSession([ResolveLocale::SESSION_KEY => 'ar'])->get('/es')->assertOk()->assertContent('es');
    }
}
