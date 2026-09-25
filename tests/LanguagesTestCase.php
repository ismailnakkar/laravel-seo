<?php

declare(strict_types=1);

namespace Seo\Tests;

use Carbon\Laravel\ServiceProvider as CarbonServiceProvider;
use Seo\SeoServiceProvider;
use Seo\Tests\Fixtures\User;

/** Four languages, accounts on the fixture User, and a browser that sends no Accept-Language unless a test does. */
abstract class LanguagesTestCase extends TestCase
{
    protected const string BROWSER = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15';

    protected function getPackageProviders($app): array
    {
        // Testbench skips package discovery, and Carbon's own provider is what makes Carbon follow the app locale.
        return [CarbonServiceProvider::class, SeoServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('seo.locales', ['en' => 'English', 'fr' => 'Français', 'ar' => 'العربية', 'es' => 'Español']);
        $app['config']->set('seo.user_locale', 'locale');
        $app['config']->set('seo.entry_redirect', ['home']);
        $app['config']->set('auth.providers.users.model', User::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createUsersTable();
        // Symfony's test requests otherwise send `Accept-Language: en-us,en;q=0.5` and `User-Agent: Symfony`.
        $this->withHeaders(['User-Agent' => self::BROWSER, 'Accept-Language' => '']);
    }
}
