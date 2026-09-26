<?php

declare(strict_types=1);

namespace Seo\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use LogicException;
use Seo\Locales;
use Seo\Tests\Fixtures\Admin;
use Seo\Tests\Fixtures\LocaleCode;
use Seo\Tests\Fixtures\User;
use Seo\UserLocale;

final class UserLocaleTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('seo.user_locale', 'locale');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createUsersTable();
    }

    public function test_it_reads_a_configured_code_and_nothing_else(): void
    {
        $locales = new Locales(['en', 'fr', 'ar'], 'en');

        $this->assertSame('fr', UserLocale::of(new User(['locale' => 'fr']), $locales));
        $this->assertNull(UserLocale::of(new User(['locale' => 'de']), $locales));
        $this->assertNull(UserLocale::of(new User(['locale' => null]), $locales));
        $this->assertNull(UserLocale::of(new GenericUser(['locale' => 'fr']), $locales));
        $this->assertNull(UserLocale::of(null, $locales));
    }

    public function test_an_enum_cast_reads_and_saves_as_its_value(): void
    {
        $user = User::create(['name' => 'a', 'locale' => 'fr']);
        $user->mergeCasts(['locale' => LocaleCode::class]);

        $this->assertSame('fr', UserLocale::of($user, new Locales(['en', 'fr', 'ar'], 'en')));

        UserLocale::save($user, 'ar');

        $this->assertSame(LocaleCode::ar, $user->locale);
        $this->assertSame('ar', User::query()->whereKey($user->getKey())->value('locale'));
    }

    public function test_without_the_setting_it_reads_and_writes_nothing(): void
    {
        config(['seo.user_locale' => null]);
        $user = User::create(['name' => 'a', 'locale' => 'fr']);
        $this->seo()->saveUserLocaleUsing(static fn () => throw new LogicException('never called'));

        $this->assertNull(UserLocale::of($user, new Locales(['en', 'fr'], 'en')));

        UserLocale::save($user, 'en');

        $this->assertSame('fr', $user->fresh()?->locale);
    }

    public function test_saving_writes_that_column_only_fires_model_events_and_syncs_the_instance(): void
    {
        $user = User::create(['name' => 'before']);
        $user->name = 'unsaved';
        $changes = [];
        Event::listen('eloquent.updated: ' . User::class, static function (User $model) use (&$changes): void {
            $changes[] = $model->getChanges();
        });

        UserLocale::save($user, 'fr');

        $fresh = $user->fresh();
        $this->assertNotNull($fresh);

        $this->assertSame('fr', $fresh->locale);
        $this->assertSame('before', $fresh->name);
        $this->assertSame('fr', $user->locale);
        $this->assertFalse($user->isDirty('locale'));
        $this->assertTrue($user->isDirty('name'));
        $this->assertCount(1, $changes);
        $this->assertArrayHasKey('locale', $changes[0]);
    }

    public function test_a_closure_replaces_the_write_and_the_instance_is_synced(): void
    {
        $user = User::create(['name' => 'member']);
        $calls = [];
        $this->seo()->saveUserLocaleUsing(static function (User $model, string $code) use (&$calls): void {
            $calls[] = [$model, $code];
        });
        $saves = 0;
        Event::listen('eloquent.saving: ' . User::class, static function () use (&$saves): void {
            $saves++;
        });

        UserLocale::save($user, 'fr');

        $this->assertSame([[$user, 'fr']], $calls);
        $this->assertSame(0, $saves);
        $this->assertNull($user->fresh()?->locale);
        $this->assertSame('fr', $user->locale);
        $this->assertFalse($user->isDirty('locale'));
    }

    public function test_a_model_without_the_column_is_neither_read_nor_written(): void
    {
        $this->createAdminsTable();
        Admin::create();
        // Read back, as a guard does: strict mode spares a model created in this request.
        $admin = Admin::query()->firstOrFail();
        $this->seo()->saveUserLocaleUsing(static fn () => throw new LogicException('never called'));
        Model::preventAccessingMissingAttributes();

        try {
            $this->assertNull(UserLocale::of($admin, new Locales(['en', 'fr'], 'en')));
            UserLocale::save($admin, 'fr');
        } finally {
            Model::preventAccessingMissingAttributes(false);
        }

        $this->assertArrayNotHasKey('locale', $admin->getAttributes());
    }

    public function test_a_deleted_or_non_eloquent_user_is_left_alone(): void
    {
        $user = User::create(['name' => 'a']);
        $user->delete();

        UserLocale::save($user, 'fr');
        UserLocale::save(new GenericUser(['id' => 1]), 'fr');

        $this->assertNull($user->locale);
    }
}
