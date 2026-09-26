<?php

declare(strict_types=1);

namespace Seo\Tests;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Seo\Http\ApplyLocale;
use Seo\Http\ResolveLocale;
use Seo\Locales;
use Seo\Page;
use Seo\ParsedPage;
use Seo\SeoServiceProvider;
use Seo\Site;

final class ConfigTest extends TestCase
{
    public function test_the_published_defaults_build_a_site_from_the_app_name_and_url(): void
    {
        config(['app.name' => 'UpFiles', 'app.url' => 'https://UpFiles.test/']);

        $this->assertEquals(
            new Site(name: 'UpFiles', url: 'https://upfiles.test'),
            $this->seo()->site(),
        );
    }

    public function test_the_head_view_publishes_with_the_seo_views_tag(): void
    {
        $paths = ServiceProvider::pathsToPublish(SeoServiceProvider::class, 'seo-views');

        $this->assertSame([resource_path('views/vendor/seo')], array_values($paths));
    }

    public function test_every_key_reaches_the_site(): void
    {
        config(['seo' => [
            'name'                => 'UpFiles',
            'url'                 => 'https://upfiles.test',
            'title_separator'     => ' | ',
            'index_by_default'    => false,
            'image'               => 'https://cdn.test/og.png',
            'logo'                => '/img/logo-512.png',
            'organization_type'   => 'OnlineBusiness',
            'alternate_names'     => ['Up Files'],
            'same_as'             => ['https://x.com/upfiles'],
            'google_verification' => 'g-code',
            'disallow'            => ['/admin/'],
            'noindex_hosts'       => ['dl.test'],
            'index_now_key'       => 'abcd-1234',
        ]]);

        $this->assertEquals(new Site(
            name: 'UpFiles',
            url: 'https://upfiles.test',
            disallow: ['/admin/'],
            image: 'https://cdn.test/og.png',
            titleSeparator: ' | ',
            indexByDefault: false,
            logo: '/img/logo-512.png',
            alternateNames: ['Up Files'],
            sameAs: ['https://x.com/upfiles'],
            googleVerification: 'g-code',
            noindexHosts: ['dl.test'],
            indexNowKey: 'abcd-1234',
            organizationType: 'OnlineBusiness',
        ), $this->seo()->site());
    }

    public function test_blank_optional_values_count_as_unset(): void
    {
        config(['app.name' => 'UpFiles', 'seo' => [
            'name'                => ' ',
            'url'                 => '',
            'title_separator'     => '',
            'index_by_default'    => null,
            'image'               => ' ',
            'logo'                => '',
            'organization_type'   => '',
            'alternate_names'     => ['', 'Up Files', null],
            'same_as'             => [null],
            'google_verification' => '',
            'disallow'            => [' '],
            'noindex_hosts'       => [''],
            'sitemap'             => ['', null],
            'index_now_key'       => '',
        ]]);

        $this->assertEquals(
            new Site(name: 'UpFiles', url: 'http://localhost', alternateNames: ['Up Files']),
            $this->seo()->site(),
        );
        $this->assertSame([], iterator_to_array($this->seo()->sitemap()));
    }

    public function test_the_defaults_render_no_share_image_tags_and_an_organization_graph(): void
    {
        config(['app.name' => 'UpFiles']);

        $response = $this->visit('/', new Page(title: 'UpFiles', suffixSiteName: false))
            ->assertOk()
            ->assertSee('<link rel="canonical" href="http://localhost/">', false)
            ->assertDontSee('og:image', false)
            ->assertDontSee('twitter:card', false);

        $this->assertSame(['Organization', 'WebSite'], array_column(ParsedPage::parse((string)$response->getContent())->jsonLd, '@type'));

        config(['seo.organization_type' => 'OnlineBusiness', 'seo.image' => '/og.png']);

        $response = $this->visit('/', new Page(title: 'UpFiles', suffixSiteName: false))
            ->assertSee('<meta property="og:image" content="http://localhost/og.png">', false)
            ->assertSee('<meta property="og:image:alt" content="UpFiles">', false)
            ->assertSee('<meta name="twitter:card" content="summary_large_image">', false);

        $this->assertSame(['OnlineBusiness', 'WebSite'], array_column(ParsedPage::parse((string)$response->getContent())->jsonLd, '@type'));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function malformedConfig(): iterable
    {
        yield 'a url with a path' => [['url' => 'https://upfiles.test/app'], 'Site::$url (seo.url, else app.url)'];
        yield 'an app url with a path' => [['url' => null, 'app_url' => 'https://upfiles.test/app'], 'Site::$url (seo.url, else app.url)'];
        yield 'a disallow entry without a slash' => [['disallow' => ['admin/']], 'Site::$disallow (seo.disallow)'];
    }

    /** @param array<string, mixed> $config */
    #[DataProvider('malformedConfig')]
    public function test_a_malformed_code_value_throws_naming_it(array $config, string $message): void
    {
        config(['app.url' => $config['app_url'] ?? 'http://localhost']);
        config(['seo' => array_diff_key($config, ['app_url' => true]) + config('seo')]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->seo()->site();
    }

    public function test_site_using_overrides_config_with_what_its_closure_returns(): void
    {
        config(['seo.name' => 'From config', 'seo.noindex_hosts' => ['dl.test']]);
        $this->app->instance('settings', ['name' => 'From settings']);

        $this->seo()->siteUsing(static fn (Application $app): array => ['name' => $app->make('settings')['name'], 'image' => '/og.png']);
        $site = $this->seo()->site();

        $this->assertSame(['From settings', '/og.png', ['dl.test']], [$site->name, $site->image, $site->noindexHosts]);
    }

    public function test_a_null_override_falls_through_to_config_and_a_blank_one_clears_it(): void
    {
        config(['seo.name' => 'From config', 'seo.image' => '/og.png']);

        $this->seo()->siteUsing(static fn (): array => ['name' => null, 'image' => '']);
        $site = $this->seo()->site();

        $this->assertSame(['From config', null], [$site->name, $site->image]);
    }

    /** @return iterable<string, array{mixed}> */
    public static function malformedLocales(): iterable
    {
        yield 'a list without names' => [['en', 'fr']];
        yield 'an empty name' => [['en' => 'English', 'fr' => ' ']];
        yield 'a malformed code' => [['en' => 'English', 'EN_us' => 'US']];
    }

    #[DataProvider('malformedLocales')]
    public function test_malformed_locales_throw(mixed $locales): void
    {
        config(['seo.locales' => $locales]);

        $this->expectException(InvalidArgumentException::class);

        Locales::configured();
    }

    public function test_the_first_of_two_or_more_locales_is_the_default_and_one_is_none(): void
    {
        config(['seo.locales' => ['fr' => 'Français', 'en' => 'English']]);
        $this->assertEquals(new Locales(['fr', 'en'], 'fr'), Locales::configured());

        config(['seo.locales' => ['en' => 'English']]);
        $this->assertNull(Locales::configured());
    }

    public function test_one_language_registers_no_middleware_and_no_switch_route(): void
    {
        $kernel = $this->app->make(Kernel::class);
        assert($kernel instanceof HttpKernel);

        $this->assertNotContains(ResolveLocale::class, $kernel->getMiddlewareGroups()['web']);
        $this->assertNotContains(ApplyLocale::class, $kernel->getMiddlewareGroups()['web']);
        $this->assertFalse(Route::has('seo.locale'));
    }

    public function test_one_language_lists_none(): void
    {
        $this->assertSame([], $this->seo()->languages());
    }
}
