<?php

declare(strict_types=1);

namespace Seo\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Contracts\Session\Middleware\AuthenticatesSessions;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application as FoundationApplication;
use Illuminate\Foundation\Configuration\ApplicationBuilder;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\URL;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Seo\Http\ApplyLocale;
use Seo\Http\ResolveLocale;
use Seo\Tests\Fixtures\Admin;
use Seo\Tests\Fixtures\AuthenticateSession as AppAuthenticateSession;
use Seo\Tests\Fixtures\LocaleCode;
use Seo\Tests\Fixtures\RequireRecentSignIn;
use Seo\Tests\Fixtures\User;

final class ResolveLocaleTest extends LanguagesTestCase
{
    protected function defineWebRoutes($router): void
    {
        $locale = static fn (): string => app()->getLocale();
        // Like cuty's login-as: the admin's session, the member for the rest of the request. Route::middleware()
        // only accepts middleware names, never a raw Closure (it casts every entry to string), so the swap happens
        // in the route's own action instead; ApplyLocale, in the `web` group, has already run by then either way.
        $loginAs = static function (Request $request) use ($locale): string {
            Auth::onceUsingId((int)$request->route('member'));

            return $locale();
        };
        $signIn = static function () use ($locale): string {
            Auth::login(User::query()->where('name', 'member')->firstOrFail());

            return $locale();
        };

        $router->localized(static function (Router $router) use ($locale, $loginAs, $signIn): void {
            $router->match(['GET', 'HEAD', 'POST', 'QUERY'], '/', $locale)->name('home');
            $router->get('terms', $locale)->name('terms');
            $router->get('login', $locale)->name('login');
            $router->get('login-as/{member}', $loginAs);
            $router->post('sign-up', static function (): string {
                Auth::login(User::create(['name' => 'new']));

                return app()->getLocale();
            });
            $router->post('sign-in', $signIn);
        });
        $router->get('plain', $locale);
        $router->post('members/sign-in', $signIn);
        $router->get('members', $locale)->middleware('auth')->name('members');
        $router->get('admin', $locale)->middleware('auth:admin');
        $router->get('limited', $locale)->middleware('throttle:1,1');
        $router->get('recent', $locale)->middleware(RequireRecentSignIn::class);
        $router->get('as/{member}', $loginAs);
        // Its XSRF cookie also reads the session, so it must go too: excluding just StartSession leaves it crashing.
        // Both names: the `web` group carries VerifyCsrfToken on Laravel 12, PreventRequestForgery from 13 on.
        $router->get('sessionless', $locale)->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, VerifyCsrfToken::class, PreventRequestForgery::class]);
    }

    public function test_the_choice_runs_straight_after_the_session_and_the_account_straight_after_authenticate_session(): void
    {
        $kernel = $this->app->make(Kernel::class);
        assert($kernel instanceof HttpKernel);
        $priority = $kernel->getMiddlewarePriority();

        $this->assertContains(ResolveLocale::class, $kernel->getMiddlewareGroups()['web']);
        $this->assertContains(ApplyLocale::class, $kernel->getMiddlewareGroups()['web']);
        // Larastan can't see getMiddlewarePriority() as a list (Laravel's own @return is bare array), so array_search()
        // types as int|string|false; the +1 needs the int cast to satisfy it.
        $this->assertSame((int)array_search(StartSession::class, $priority, true) + 1, array_search(ResolveLocale::class, $priority, true));
        $this->assertSame((int)array_search(AuthenticatesSessions::class, $priority, true) + 1, array_search(ApplyLocale::class, $priority, true));
    }

    /**
     * cuty's bootstrap/app.php: its session check, a subclass it ranks by name, straight after `auth`, ApplyLocale after
     * it as the README says, and its gates ahead of the throttles. Through the builder's own hook, which runs before any
     * package's.
     */
    protected function sessionCheckRankedEarly(FoundationApplication $app): void
    {
        new ApplicationBuilder($app)->withMiddleware(static function (Middleware $middleware): void {
            $middleware->appendToPriorityList(after: AuthenticatesRequests::class, append: AppAuthenticateSession::class);
            $middleware->appendToPriorityList(after: AppAuthenticateSession::class, append: ApplyLocale::class);
            $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: PreventRequestForgery::class);
            $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: VerifyCsrfToken::class); // Laravel 12's
            $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: RequireRecentSignIn::class);
            $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: ValidateSignature::class);
            $middleware->web(append: AppAuthenticateSession::class);
        });
    }

    #[DefineEnvironment('sessionCheckRankedEarly')]
    public function test_an_app_ranking_the_account_after_its_early_session_check_has_its_gates_refuse_in_the_accounts_language(): void
    {
        $this->app->make(Kernel::class); // runs the app's hook, then the package's
        $router = $this->app->make(Router::class);
        $order = [ResolveLocale::class, AppAuthenticateSession::class, ApplyLocale::class, RequireRecentSignIn::class];
        $this->assertSame($order, array_values(array_intersect($router->gatherRouteMiddleware($router->getRoutes()->match(Request::create('/recent'))), $order)));

        $this->assertARevokedSessionWritesNothing();

        $member = User::create(['name' => 'member', 'locale' => 'fr', 'password' => 'hash-now']);
        $this->actingAs($member)->withSession([ResolveLocale::SESSION_KEY => 'es'])->get('/recent')->assertStatus(423)->assertContent('fr');
    }

    /** An app's own priority list without AuthenticateSession, set before any package's hook as the builder's is. */
    protected function priorityWithoutAuthenticateSession(Application $app): void
    {
        $app->afterResolving(Kernel::class, static function (HttpKernel $kernel): void {
            // Not StartSession first: older Laravel ranks "after the first entry" at the end of the list.
            $kernel->setMiddlewarePriority([EncryptCookies::class, StartSession::class, AuthenticatesRequests::class, SubstituteBindings::class]);
            $kernel->appendMiddlewareToGroup('web', AuthenticateSession::class);
        });
    }

    #[DefineEnvironment('priorityWithoutAuthenticateSession')]
    public function test_a_priority_list_without_authenticate_session_still_puts_the_account_after_both_auth_checks(): void
    {
        $this->app->make(Kernel::class); // runs the app's hook, then the package's
        $router = $this->app->make(Router::class);
        $route = $router->getRoutes()->getByName('members');
        assert($route !== null);

        $order = array_values(array_intersect(
            $router->gatherRouteMiddleware($route),
            [ResolveLocale::class, Authenticate::class, AuthenticateSession::class, ApplyLocale::class],
        ));

        $this->assertCount(4, $order);
        $this->assertSame(ResolveLocale::class, $order[0]);
        $this->assertSame(ApplyLocale::class, $order[3]);
    }

    public function test_a_revoked_remember_me_cookie_is_not_signed_in_by_the_entry_redirect(): void
    {
        $this->withAuthenticateSession();
        $member = User::create(['name' => 'member', 'locale' => 'fr', 'password' => 'hash-now', 'remember_token' => 'token']);

        // Its third part is the password the cookie was issued under, changed since. Newer frameworks' guard refuses it
        // itself; older ones sign it in and leave AuthenticateSession to throw it out.
        $this->withCookie($this->recallerName(), "{$member->id}|token|hash-before")->get('/');

        $this->assertGuestNextTime();
    }

    public function test_a_revoked_session_never_writes_the_account(): void
    {
        $this->withAuthenticateSession();

        $this->assertARevokedSessionWritesNothing();
    }

    public function test_a_valid_remember_me_cookie_still_lands_on_the_accounts_language(): void
    {
        $this->withAuthenticateSession();
        $member = User::create(['name' => 'member', 'locale' => 'fr', 'password' => 'hash-now', 'remember_token' => 'token']);

        $this->withCookie($this->recallerName(), "{$member->id}|token|hash-now")->get('/')->assertRedirect('/fr');
    }

    public function test_the_choice_never_signs_in_a_remember_me_cookie_for_a_refusal_ahead_of_authenticate_session(): void
    {
        $member = User::create(['name' => 'member', 'locale' => 'fr', 'password' => 'hash-now', 'remember_token' => 'token']);
        // Unit tests skip CSRF. offsetSet(), not `$this->app['env'] =`, which Larastan misreads.
        $this->app->offsetSet('env', 'production');

        $this->withCookie($this->recallerName(), "{$member->id}|token|hash-now")->post('/')->assertStatus(419);

        $this->assertGuestNextTime();
    }

    public function test_a_guest_turned_away_by_auth_lands_on_the_login_page_in_their_language(): void
    {
        $this->withSession([ResolveLocale::SESSION_KEY => 'fr'])->get('/members')->assertRedirect('/fr/login');
    }

    public function test_a_copy_renders_its_own_language_and_opening_it_makes_that_the_choice(): void
    {
        $this->withSession([ResolveLocale::SESSION_KEY => 'fr']);

        $this->get('/es/terms')->assertContent('es');
        $this->get('/plain')->assertContent('es');
        $this->get('/terms')->assertContent('en');
        $this->get('/plain')->assertContent('en');
    }

    public function test_the_account_beats_the_session_and_corrects_it(): void
    {
        $user = User::create(['name' => 'member', 'locale' => 'ar']);

        $this->actingAs($user)->withSession([ResolveLocale::SESSION_KEY => 'fr'])->get('/plain')->assertContent('ar');

        $this->assertSame('ar', session(ResolveLocale::SESSION_KEY));
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

    /** @return iterable<string, array{string, array<string, string>}> URI, headers */
    public static function firstRequestsOpeningNoCopy(): iterable
    {
        yield 'an <img> on another site' => ['/fr/terms', ['Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Dest' => 'image']];
        yield 'a forwarded signed link' => ['/fr/terms?signature=x', []];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('firstRequestsOpeningNoCopy')]
    public function test_a_sessions_first_request_that_does_not_open_its_copy_sets_no_choice(string $uri, array $headers): void
    {
        $this->withHeaders(['Accept-Language' => 'es', ...$headers])->get($uri)->assertContent('fr');

        // Not /fr/login, where signing in would give the account the copy's language.
        $this->get('/members')->assertRedirect('/es/login');
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

    public function test_an_account_without_a_language_takes_the_copys(): void
    {
        $user = User::create(['name' => 'member']);

        $this->actingAs($user)->withSession([ResolveLocale::SESSION_KEY => 'es'])->get('/fr/terms')->assertContent('fr');

        $this->assertSame('fr', $user->fresh()?->locale);
    }

    public function test_opening_a_copy_saves_its_language_to_the_session_and_the_account_by_any_method(): void
    {
        $user = User::create(['name' => 'member', 'locale' => 'ar']);

        $this->actingAs($user)->withSession([ResolveLocale::SESSION_KEY => 'fr'])->get('/es/terms')->assertContent('es');

        $this->assertSame('es', session(ResolveLocale::SESSION_KEY));
        $this->assertSame('es', $user->fresh()?->locale);
        $this->get('/plain')->assertContent('es');

        $this->post('/fr')->assertContent('fr');

        $this->assertSame('fr', User::query()->whereKey($user->id)->value('locale'));
    }

    public function test_a_copy_in_the_accounts_language_writes_nothing(): void
    {
        $user = User::create(['name' => 'member', 'locale' => 'fr']);
        $user->mergeCasts(['locale' => LocaleCode::class]);
        $codes = [];
        $this->seo()->saveUserLocaleUsing(static function (User $user, string $code) use (&$codes): void {
            $codes[] = $code;
        });

        $this->actingAs($user)->get('/fr/terms')->assertContent('fr');
        $this->get('/plain')->assertContent('fr');

        $this->assertSame([], $codes);
    }

    public function test_a_click_inside_the_site_onto_the_default_copy_saves_the_default(): void
    {
        $member = User::create(['name' => 'member', 'locale' => 'fr']);

        $this->actingAs($member)->withHeaders(['Sec-Fetch-Site' => 'same-origin'])->get('/')->assertOk()->assertContent('en');

        $this->assertSame('en', session(ResolveLocale::SESSION_KEY));
        $this->assertSame('en', $member->fresh()?->locale);
    }

    public function test_a_page_visit_from_another_site_saves_its_language(): void
    {
        $member = User::create(['name' => 'member', 'locale' => 'en']);

        $this->actingAs($member)->withHeaders(['Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Dest' => 'document'])->get('/es/terms')->assertContent('es');

        $this->assertSame('es', session(ResolveLocale::SESSION_KEY));
        $this->assertSame('es', $member->fresh()?->locale);
    }

    public function test_a_signed_link_never_saves_its_language(): void
    {
        $member = User::create(['name' => 'member', 'locale' => 'fr']);
        $url = URL::signedRoute('terms');

        // From webmail: whoever sent the mail chose the link's language, not the member.
        $this->actingAs($member)->withHeaders(['Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Dest' => 'document'])->get($url)->assertOk()->assertContent('en');

        $this->assertSame('fr', session(ResolveLocale::SESSION_KEY));
        $this->assertSame('fr', $member->fresh()?->locale);
    }

    /** @return iterable<string, array{array<string, string>, bool}> headers, whether the copy's language is saved */
    public static function loadsOfACopy(): iterable
    {
        yield 'an <img> on another site' => [['Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Dest' => 'image'], false];
        yield 'an <img> on the same origin' => [['Sec-Fetch-Site' => 'same-origin', 'Sec-Fetch-Dest' => 'image'], false];
        yield 'an <iframe> on the same site' => [['Sec-Fetch-Site' => 'same-site', 'Sec-Fetch-Dest' => 'iframe'], false];
        yield 'a click on the same origin' => [['Sec-Fetch-Site' => 'same-origin', 'Sec-Fetch-Dest' => 'document'], true];
        yield 'a fetch on the same origin: Inertia, wire:navigate' => [['Sec-Fetch-Site' => 'same-origin', 'Sec-Fetch-Dest' => 'empty', 'X-Livewire-Navigate' => '1'], true];
        yield 'a Livewire update replaying the page, which drops its query' => [['Sec-Fetch-Site' => 'same-origin', 'Sec-Fetch-Dest' => 'empty', 'X-Livewire' => '1'], false];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('loadsOfACopy')]
    public function test_only_a_page_load_or_the_apps_own_fetch_saves_a_copys_language(array $headers, bool $saves): void
    {
        $member = User::create(['name' => 'member', 'locale' => 'en']);
        $codes = [];
        $this->seo()->saveUserLocaleUsing(static function (User $user, string $code) use (&$codes): void {
            $codes[] = $code;
        });

        $this->actingAs($member)->withHeaders($headers)->get('/ar')->assertOk()->assertContent('ar');

        $this->assertSame($saves ? ['ar'] : [], $codes);
        $this->assertSame($saves ? 'ar' : 'en', session(ResolveLocale::SESSION_KEY));
    }

    public function test_a_copy_embedded_by_another_site_never_saves_its_language(): void
    {
        $member = User::create(['name' => 'member']);

        // An <img>: a SameSite=None session cookie follows it from any site. The empty account still gets the choice.
        $this->actingAs($member)->withSession([ResolveLocale::SESSION_KEY => 'es'])
            ->withHeaders(['Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Dest' => 'image'])->get('/ar')->assertOk()->assertContent('ar');

        $this->assertSame('es', session(ResolveLocale::SESSION_KEY));
        $this->assertSame('es', $member->fresh()?->locale);
    }

    #[DefineEnvironment('sessionCheckRankedEarly')]
    public function test_a_forged_post_refused_by_csrf_ranked_after_the_account_saves_nothing(): void
    {
        $member = User::create(['name' => 'member', 'locale' => 'en', 'password' => 'hash-now']);
        $codes = [];
        $this->seo()->saveUserLocaleUsing(static function (User $user, string $code) use (&$codes): void {
            $codes[] = $code;
        });
        $this->app->offsetSet('env', 'production');

        $this->actingAs($member)->withHeaders(['Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Dest' => 'document'])->post('/ar')->assertStatus(419);

        $this->assertSame([], $codes);
    }

    public function test_signing_up_saves_the_pages_language(): void
    {
        // The browser's copy too: it replaces no account's language, but fills an empty one.
        $this->withSession([ResolveLocale::SESSION_KEY => 'es'])->withHeaders(['Accept-Language' => 'fr'])->post('/fr/sign-up')->assertContent('fr');

        $this->assertSame('fr', User::query()->where('name', 'new')->value('locale'));
    }

    public function test_signing_in_on_a_copy_saves_its_language_to_the_account(): void
    {
        $member = User::create(['name' => 'member', 'locale' => 'ar']);

        $this->withSession([ResolveLocale::SESSION_KEY => 'es'])->get('/fr/login')->assertContent('fr');
        $this->post('/fr/sign-in')->assertContent('fr');

        $this->assertSame('fr', $member->fresh()?->locale);
        $this->get('/members')->assertOk()->assertContent('fr');
    }

    /** @return iterable<string, array{string, string}> the browser's language, the login page's prefix */
    public static function newDevices(): iterable
    {
        yield 'a browser in another language' => ['es', '/es'];
        yield 'a browser in none of them' => ['de', ''];
    }

    #[DataProvider('newDevices')]
    public function test_signing_in_on_the_copy_the_browser_picked_keeps_the_accounts_language(string $browser, string $prefix): void
    {
        $member = User::create(['name' => 'member', 'locale' => 'ar']);

        // No session yet: `auth` sends the guest to the login page in the browser's language, not one they chose.
        $this->withHeaders(['Accept-Language' => $browser])->get('/members')->assertRedirect("{$prefix}/login");
        $this->post("{$prefix}/sign-in")->assertOk();

        $this->assertSame('ar', $member->fresh()?->locale);
        $this->get('/members')->assertContent('ar');
    }

    /** @return iterable<string, array{string, ?string, string}> URI, the account's language before and after */
    public static function signInsOpeningNoCopy(): iterable
    {
        yield 'off a copy' => ['/members/sign-in', 'ar', 'ar'];
        yield 'through a signed link' => ['/fr/sign-in?signature=x', 'ar', 'ar'];
        yield 'through a signed link, an account without a language' => ['/fr/sign-in?signature=x', null, 'es'];
    }

    #[DataProvider('signInsOpeningNoCopy')]
    public function test_signing_in_without_opening_a_copy_keeps_the_choice(string $uri, ?string $before, string $after): void
    {
        $member = User::create(['name' => 'member', 'locale' => $before]);
        $codes = [];
        $this->seo()->saveUserLocaleUsing(static function (User $user, string $code) use (&$codes): void {
            $codes[] = $code;
            User::query()->whereKey($user->getKey())->update(['locale' => $code]);
        });

        $this->withSession([ResolveLocale::SESSION_KEY => 'es'])->post($uri)->assertOk();

        $this->assertSame($before === $after ? [] : [$after], $codes);
        $this->assertSame($after, $member->fresh()?->locale);
        $this->get('/plain')->assertContent($after);
    }

    public function test_an_admin_signed_in_as_a_member_never_writes_the_members_account(): void
    {
        $admin = User::create(['name' => 'admin', 'locale' => 'ar']);
        $member = User::create(['name' => 'member']);

        $this->actingAs($admin)->get("/as/{$member->id}")->assertOk();

        $this->assertNull($member->fresh()?->locale);
        $this->assertSame('ar', $admin->fresh()?->locale);
    }

    public function test_an_admin_signed_in_as_a_member_on_a_copy_saves_only_the_admins_account(): void
    {
        $admin = User::create(['name' => 'admin', 'locale' => 'ar']);
        $member = User::create(['name' => 'member']);

        $this->actingAs($admin)->get("/fr/login-as/{$member->id}")->assertOk()->assertContent('fr');

        $this->assertNull($member->fresh()?->locale);
        $this->assertSame('fr', $admin->fresh()?->locale);
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

    public function test_a_fill_goes_through_the_saving_closure_with_the_requests_user(): void
    {
        $user = User::create(['name' => 'member']);
        $calls = [];
        $this->seo()->saveUserLocaleUsing(static function (User $model, string $code) use (&$calls): void {
            $calls[] = [$model, $code];
            User::query()->whereKey($model->getKey())->update(['locale' => $code]);
        });

        $this->actingAs($user)->withSession([ResolveLocale::SESSION_KEY => 'es'])->get('/plain')->assertContent('es');

        $this->assertSame([[$user, 'es']], $calls);
        $this->assertSame('es', $user->fresh()?->locale);
        $this->assertSame('es', $user->locale);
        $this->assertFalse($user->isDirty('locale'));
    }

    public function test_signing_up_saves_the_pages_language_through_the_closure(): void
    {
        $codes = [];
        $this->seo()->saveUserLocaleUsing(static function (User $user, string $code) use (&$codes): void {
            $codes[] = $code;
        });

        $this->post('/fr/sign-up')->assertContent('fr');

        $this->assertSame(['fr'], $codes);
    }

    public function test_a_throwing_closure_in_a_fill_is_reported_and_the_page_still_served(): void
    {
        Exceptions::fake();
        $this->seo()->saveUserLocaleUsing(static fn () => throw new RuntimeException('service down'));
        $user = User::create(['name' => 'member']);

        $this->actingAs($user)->withHeaders(['Accept-Language' => 'fr'])->get('/plain')->assertOk()->assertContent('fr');

        Exceptions::assertReported(static fn (RuntimeException $e): bool => $e->getMessage() === 'service down');
        $this->assertNull($user->locale);
    }

    /** @return iterable<string, array{bool, bool}> a member signed in too, Model::preventAccessingMissingAttributes() */
    public static function adminPages(): iterable
    {
        yield 'an admin and a member' => [true, false];
        yield 'an admin and a member, strict' => [true, true];
        yield 'an admin' => [false, false];
        yield 'an admin, strict' => [false, true];
    }

    #[DataProvider('adminPages')]
    public function test_another_guards_model_without_the_column_is_left_alone(bool $member, bool $strict): void
    {
        $this->createAdminsTable();
        config([
            'auth.guards.admin'     => ['driver' => 'session', 'provider' => 'admins'],
            'auth.providers.admins' => ['driver' => 'eloquent', 'model' => Admin::class],
        ]);
        Exceptions::fake();

        if ($member) {
            $this->actingAs(User::create(['name' => 'member', 'locale' => 'fr']));
        }

        $this->actingAs(Admin::create(), 'admin');
        // actingAs() made the admin guard the default; on the page, auth:admin must.
        Auth::shouldUse('web');
        Model::preventAccessingMissingAttributes($strict);

        try {
            $this->withSession([ResolveLocale::SESSION_KEY => 'es'])->get('/admin')->assertOk()->assertContent('es');
        } finally {
            Model::preventAccessingMissingAttributes(false);
        }

        Exceptions::assertNothingReported();
    }

    public function test_a_refusal_ahead_of_the_route_speaks_the_visitors_language(): void
    {
        $this->withSession([ResolveLocale::SESSION_KEY => 'fr'])->get('/limited')->assertOk();
        $this->app->setLocale('en');

        $this->get('/limited')->assertStatus(429);

        $this->assertSame('fr', $this->app->getLocale());
    }

    public function test_a_refusal_on_a_copy_speaks_the_copys_language(): void
    {
        $this->app->offsetSet('env', 'production');

        $this->withSession([ResolveLocale::SESSION_KEY => 'es'])->post('/fr/sign-up')->assertStatus(419);

        $this->assertSame('fr', $this->app->getLocale());
    }

    public function test_a_sessions_refused_first_request_still_keeps_the_choice(): void
    {
        $this->app->offsetSet('env', 'production');

        $this->post('/es')->assertStatus(419);

        $this->get('/plain')->assertContent('es');
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
        $member = User::create(['name' => 'member', 'locale' => 'ar']);

        $this->actingAs($member)->withSession([ResolveLocale::SESSION_KEY => 'es'])->get('/')->assertRedirect('/ar');

        $this->assertSame('es', session(ResolveLocale::SESSION_KEY));
        $this->assertSame('ar', $member->fresh()?->locale);
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

    /**
     * Revoked by a password change elsewhere (logoutOtherDevices()): the session's hash is stale. Unlike a stale
     * remember-me cookie, which newer guards refuse themselves, only AuthenticateSession catches it.
     */
    private function assertARevokedSessionWritesNothing(): void
    {
        $member = User::create(['name' => 'member', 'password' => 'hash-now']);

        $this->actingAs($member)->withSession(['password_hash_web' => 'stale', ResolveLocale::SESSION_KEY => 'es'])->get('/plain');

        $this->assertNull($member->fresh()?->locale);
    }

    private function withAuthenticateSession(): void
    {
        $kernel = $this->app->make(Kernel::class);
        assert($kernel instanceof HttpKernel);
        $kernel->appendMiddlewareToGroup('web', AuthenticateSession::class);
    }

    private function recallerName(): string
    {
        $guard = Auth::guard('web');
        assert($guard instanceof SessionGuard);

        return $guard->getRecallerName();
    }

    /** The next visit, without the cookie: only what the last one left in the session can sign it in. */
    private function assertGuestNextTime(): void
    {
        $this->defaultCookies = [];
        Auth::forgetGuards();

        $this->get('/plain');

        $this->assertGuest();
    }
}
