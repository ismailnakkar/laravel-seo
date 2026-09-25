<?php

declare(strict_types=1);

namespace Seo\Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;

final class InstallCommandTest extends TestCase
{
    private string $public;

    protected function setUp(): void
    {
        parent::setUp();

        $this->public = sys_get_temp_dir() . '/seo-install-' . getmypid();
        (new Filesystem)->ensureDirectoryExists($this->public);
        $this->app->usePublicPath($this->public);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->public);
        (new Filesystem)->delete(config_path('seo.php'));

        parent::tearDown();
    }

    public function test_it_publishes_the_config_and_deletes_laravels_stock_robots_txt_unasked(): void
    {
        file_put_contents("{$this->public}/robots.txt", "User-agent: *\r\nDisallow:\r\n");

        $this->artisan('seo:install')->expectsOutputToContain('Deleted public/robots.txt')->assertSuccessful();

        $this->assertFileDoesNotExist("{$this->public}/robots.txt");
        $this->assertFileEquals(__DIR__ . '/../config/seo.php', config_path('seo.php'));
    }

    public function test_it_asks_before_deleting_a_file_with_content_of_its_own(): void
    {
        file_put_contents("{$this->public}/robots.txt", "User-agent: *\nDisallow: /admin/\n");
        file_put_contents("{$this->public}/sitemap.xml", '<urlset/>');

        $this->artisan('seo:install')
            ->expectsConfirmation('Delete public/robots.txt? It is not Laravel\'s stock file: move any rules you need into config/seo.php first.', 'no')
            ->expectsConfirmation('Delete public/sitemap.xml? It is not Laravel\'s stock file: move any rules you need into config/seo.php first.', 'yes')
            ->expectsOutputToContain('Kept public/robots.txt')
            ->assertSuccessful();

        $this->assertFileExists("{$this->public}/robots.txt");
        $this->assertFileDoesNotExist("{$this->public}/sitemap.xml");
    }

    public function test_without_interaction_it_keeps_a_file_with_content_of_its_own(): void
    {
        file_put_contents("{$this->public}/indexnow-key.txt", 'a-key-of-its-own');

        // artisan() mocks every question; Artisan::call() answers them as a real non-interactive run does.
        $this->assertSame(0, Artisan::call('seo:install', ['--no-interaction' => true]));
        $this->assertStringContainsString('Kept public/indexnow-key.txt', Artisan::output());
        $this->assertFileExists("{$this->public}/indexnow-key.txt");
    }

    /** @return iterable<string, array{string}> ralphjsmit/laravel-seo's config/seo.php, cut to what gives it away */
    public static function foreignConfigs(): iterable
    {
        yield 'a model' => ["<?php return ['model' => 'App\\Models\\Seo', 'image' => null];\n"];
        yield 'an image array' => ["<?php return ['image' => ['fallback' => null]];\n"];
    }

    #[DataProvider('foreignConfigs')]
    public function test_another_packages_config_is_refused_before_anything_changes(string $config): void
    {
        file_put_contents(config_path('seo.php'), $config);
        file_put_contents("{$this->public}/robots.txt", "User-agent: *\nDisallow:\n");

        $this->artisan('seo:install')->expectsOutputToContain('config/seo.php belongs to another package')->assertExitCode(1);

        $this->assertSame($config, file_get_contents(config_path('seo.php')));
        $this->assertFileExists("{$this->public}/robots.txt");
    }

    public function test_a_failed_delete_is_an_error(): void
    {
        file_put_contents("{$this->public}/robots.txt", "User-agent: *\nDisallow:\n");
        $this->partialMock(Filesystem::class, static fn (MockInterface $files) => $files->shouldReceive('delete')->andReturnFalse());

        $this->artisan('seo:install')
            ->expectsOutputToContain('Could not delete public/robots.txt')
            ->doesntExpectOutputToContain('Deleted')
            ->assertExitCode(1);
    }

    public function test_an_existing_config_is_kept(): void
    {
        file_put_contents(config_path('seo.php'), "<?php return ['name' => 'Mine'];\n");

        $this->artisan('seo:install')->assertSuccessful();

        $this->assertStringContainsString("'Mine'", (string)file_get_contents(config_path('seo.php')));
    }
}
