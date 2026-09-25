<?php

declare(strict_types=1);

namespace Seo\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'seo:install', description: 'Publish config/seo.php and delete the public/ files that would hide the package routes')]
final class InstallCommand extends Command
{
    /** What the package's routes serve: a copy in public/ is served by the web server before the route runs. */
    public const array SHADOWS = ['robots.txt', 'sitemap.xml', 'indexnow-key.txt'];

    /** laravel/laravel's public/robots.txt: nothing in it to keep. */
    private const string STOCK_ROBOTS = "User-agent: *\nDisallow:";

    public function handle(Filesystem $files): int
    {
        $config = $this->laravel->configPath('seo.php');
        $existing = $files->exists($config) ? (array)$files->getRequire($config) : [];

        // ralphjsmit/laravel-seo's shape. vendor:publish never overwrites it, and no Site builds from it.
        if (array_key_exists('model', $existing) || ! is_scalar($existing['image'] ?? '')) {
            $this->fail('config/seo.php belongs to another package, which reads the same config key: remove that package and its config/seo.php, then run seo:install again.');
        }

        $this->call('vendor:publish', ['--tag' => 'seo-config']);

        foreach (self::SHADOWS as $name) {
            $path = $this->laravel->publicPath($name);

            if (! $files->exists($path)) {
                continue;
            }

            $stock = $name === 'robots.txt' && trim(str_replace("\r\n", "\n", $files->get($path))) === self::STOCK_ROBOTS;

            if ($stock || $this->confirm("Delete public/{$name}? It is not Laravel's stock file: move any rules you need into config/seo.php first.")) {
                if (! $files->delete($path)) {
                    $this->fail("Could not delete public/{$name}: the web server still serves it instead of the package's route.");
                }

                $this->components->info("Deleted public/{$name}: the package's route serves it now.");
            } else {
                $this->components->warn("Kept public/{$name}: the web server serves it instead of the package's route.");
            }
        }

        $this->components->info('Next: put <x-seo::head /> at the top of your layout\'s <head>, after charset and viewport, and describe each page with @seo(...) in its view.');

        return self::SUCCESS;
    }
}
