<?php

declare(strict_types=1);

namespace Seo\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Seo\HostRole;
use Seo\Site;
use Seo\Testing\RobotsMatcher;

final class RobotsMatcherTest extends TestCase
{
    public function test_a_named_group_replaces_star(): void
    {
        $matcher = new RobotsMatcher("User-agent: *\nDisallow: /admin/\n\nUser-agent: GPTBot\nUser-agent: ClaudeBot\nDisallow: /\n");

        $this->assertFalse($matcher->allows('GPTBot', '/faq'));
        $this->assertFalse($matcher->allows('claudebot', '/faq'), 'tokens match case-insensitively');
        $this->assertFalse($matcher->allows('Googlebot', '/admin/x'));
        $this->assertTrue($matcher->allows('Googlebot', '/faq'));
    }

    public function test_disallow_is_a_prefix_with_wildcards_and_an_end_anchor(): void
    {
        $matcher = self::matcher(['/admin/', '/*.pdf$', '/files/*/raw']);

        $this->assertFalse($matcher->allows('Googlebot', '/admin/'));
        $this->assertFalse($matcher->allows('Googlebot', '/admin/users?page=2'));
        $this->assertTrue($matcher->allows('Googlebot', '/admin'));
        $this->assertFalse($matcher->allows('Googlebot', '/docs/a.pdf'));
        $this->assertTrue($matcher->allows('Googlebot', '/docs/a.pdf?v=1'), 'the anchor ends path and query');
        $this->assertTrue($matcher->allows('Googlebot', '/docs/a.PDF'), 'matching is case-sensitive');
        $this->assertFalse($matcher->allows('Googlebot', '/files/a/b/raw/x'));
        $this->assertTrue($matcher->allows('Googlebot', '/files/raw'));
    }

    public function test_an_empty_policy_allows_everything(): void
    {
        $this->assertTrue(self::matcher([])->allows('Googlebot', '/anything'));
        $this->assertTrue((new RobotsMatcher(''))->allows('Googlebot', '/'));
    }

    public function test_robots_txt_is_always_allowed(): void
    {
        $matcher = self::matcher(['/']);

        $this->assertTrue($matcher->allows('GPTBot', '/robots.txt'));
        $this->assertTrue($matcher->allows('Googlebot', '/robots.txt?v=1'));
        $this->assertFalse($matcher->allows('Googlebot', '/robots.txt.bak'));
    }

    /** @return iterable<string, array{string, string}> a disallow pattern, a path under it */
    public static function encodings(): iterable
    {
        yield 'UTF-8 pattern, encoded path' => ['/über/', '/%C3%BCber/x'];
        yield 'encoded pattern, UTF-8 path' => ['/%C3%BCber/', '/über/x'];
        yield 'lower-case hex' => ['/%c3%bcber/', '/%C3%BCber/x'];
    }

    #[DataProvider('encodings')]
    public function test_non_ascii_octets_compare_percent_encoded(string $pattern, string $path): void
    {
        $this->assertFalse(self::matcher([$pattern])->allows('Googlebot', $path));
    }

    public function test_a_url_instead_of_a_path_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::matcher(['/admin/'])->allows('Googlebot', 'https://x.test/admin/');
    }

    public function test_a_user_agent_string_instead_of_a_product_token_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('product token');

        self::matcher([])->allows('Mozilla/5.0 (compatible; GPTBot/1.1; +https://openai.com/gptbot)', '/faq');
    }

    /** @param list<string> $disallow */
    private static function matcher(array $disallow): RobotsMatcher
    {
        return new RobotsMatcher((new Site(name: 'x', url: 'https://x.test', disallow: $disallow))->robotsTxt(HostRole::index, 'https://x.test/sitemap.xml'));
    }
}
