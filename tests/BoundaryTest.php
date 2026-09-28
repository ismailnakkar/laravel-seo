<?php

declare(strict_types=1);

namespace Seo\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

final class BoundaryTest extends TestCase
{
    /** A text scan: PHPStan never sees a name inside class_exists(), a string or a docblock. */
    public function test_nothing_the_package_ships_names_a_localization_package(): void
    {
        $root = dirname(__DIR__);
        $hits = [];

        foreach (Finder::create()->files()->in(array_map(static fn (string $dir): string => "{$root}/{$dir}", ['src', 'config', 'routes', 'resources'])) as $file) {
            foreach (explode("\n", $file->getContents()) as $i => $line) {
                if (preg_match('/localization[\\\\.]/i', $line) === 1) {
                    $hits[] = substr($file->getPathname(), strlen($root) + 1) . ':' . ($i + 1) . ': ' . trim($line);
                }
            }
        }

        $this->assertSame([], $hits, 'Localization\ or localization. named in: ' . implode("\n", $hits));
    }
}
