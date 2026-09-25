<?php

declare(strict_types=1);

namespace Seo\Tests;

use Closure;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;

final class IndexNowTest extends TestCase
{
    private const string KEY = '3f9c2b7e-4a1d-4f0e-9b8c-5d6e7f8a9b0c';

    private const string ENDPOINT = 'https://api.indexnow.org/IndexNow';

    /** @return iterable<string, array{string|null}> */
    public static function malformedKeys(): iterable
    {
        yield 'unset' => [null];
        yield 'empty' => [''];
        yield '7 characters' => ['abc-123'];
        yield '129 characters' => [str_repeat('a', 129)];
        yield 'underscore' => ['abcd_1234'];
        yield 'space' => ['abcd 1234'];
        yield 'non-ASCII letter' => ['abcdé1234'];
        // `$` alone also matches before a final newline, which IndexNow would get as part of the key.
        yield 'trailing newline' => ["abcd-1234\n"];
    }

    /** @return iterable<string, array{string}> */
    public static function validKeys(): iterable
    {
        yield '8 characters' => ['abcd-123'];
        yield '128 characters' => [str_repeat('Z9-', 42) . 'ab'];
    }

    /** @return iterable<string, array{string}> */
    public static function offHostUrls(): iterable
    {
        yield 'crawl host' => ['http://go.test/faq'];
        yield 'noindex host' => ['http://dl.test/'];
        yield 'a protocol-relative URL' => ['//evil.test/faq'];
        yield 'a host that starts with the site host' => ['http://localhost.evil.test/faq'];
        yield 'the site host in the query' => ['http://evil.test/?h=localhost'];
        yield 'not a URL' => ['http:///faq'];
        yield 'a host with no scheme' => ['localhost/faq'];
    }

    #[DataProvider('validKeys')]
    public function test_the_key_file_is_the_bare_key_as_plain_text(string $key): void
    {
        $this->withSite(['index_now_key' => $key]);

        $this->get('/indexnow-key.txt')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertHeader('Cache-Control', 'max-age=3600, private')
            ->assertContent($key);
    }

    #[DataProvider('malformedKeys')]
    public function test_the_key_file_is_404_for_an_unset_or_malformed_key(?string $key): void
    {
        $this->withSite(['index_now_key' => $key]);

        $this->get('/indexnow-key.txt')->assertNotFound();
    }

    public function test_the_command_needs_urls_or_all(): void
    {
        $this->ready();

        $this->artisan('seo:indexnow')
            ->expectsOutputToContain('Pass the URLs that changed, or --all after a migration or redesign.')
            ->assertExitCode(1)
            ->run();

        Http::assertNothingSent();
    }

    #[DataProvider('malformedKeys')]
    public function test_an_unset_or_malformed_key_sends_nothing(?string $key): void
    {
        $this->ready(['index_now_key' => $key]);

        $this->artisan('seo:indexnow', ['url' => ['http://localhost/faq']])
            ->expectsOutputToContain('Set seo.index_now_key')
            ->assertExitCode(1)
            ->run();

        Http::assertNothingSent();
    }

    #[DefineEnvironment('withoutRoutes')]
    public function test_a_missing_key_route_sends_nothing(): void
    {
        Http::fake();
        $this->withSite(['index_now_key' => self::KEY]);

        $this->artisan('seo:indexnow', ['url' => ['http://localhost/faq']])
            ->expectsOutputToContain('The key file route is not registered: set seo.routes to true, or name your own key route seo.indexnow.')
            ->assertExitCode(1)
            ->run();

        Http::assertNothingSent();
    }

    #[DataProvider('offHostUrls')]
    public function test_a_url_off_the_site_host_sends_nothing(string $url): void
    {
        $this->ready();

        $this->artisan('seo:indexnow', ['url' => ['http://localhost/faq', $url]])
            ->expectsOutputToContain($url)
            ->assertExitCode(1)
            ->run();

        Http::assertNothingSent();
    }

    public function test_the_submission_matches_the_protocol(): void
    {
        $this->ready(['url' => 'https://upfiles.com']);

        $this->artisan('seo:indexnow', ['url' => ['https://upfiles.com/pricing', 'https://UPFILES.com/faq']])
            ->expectsOutput('PASS 200')
            ->assertExitCode(0)
            ->run();

        Http::assertSentCount(1);
        Http::assertSent(static fn (ClientRequest $request): bool => $request->method() === 'POST'
            && $request->url() === self::ENDPOINT
            && $request->header('Content-Type') === ['application/json; charset=utf-8']
            && $request->body() === '{"host":"upfiles.com","key":"' . self::KEY . '","keyLocation":"https://upfiles.com/indexnow-key.txt","urlList":["https://upfiles.com/pricing","https://UPFILES.com/faq"]}');
    }

    public function test_a_path_is_sent_on_the_site_origin(): void
    {
        $this->ready(['url' => 'https://upfiles.com']);

        $this->artisan('seo:indexnow', ['url' => ['/pricing', 'https://upfiles.com/faq']])->assertExitCode(0)->run();

        $this->assertSame([['https://upfiles.com/pricing', 'https://upfiles.com/faq']], array_column($this->sent(), 'urlList'));
    }

    public function test_urls_are_sent_in_chunks_of_ten_thousand(): void
    {
        $this->ready();
        $urls = array_map(static fn (int $i): string => "http://localhost/p/{$i}", range(1, 20_001));

        $this->artisan('seo:indexnow', ['url' => $urls])->assertExitCode(0)->run();

        $requests = $this->sent();
        $this->assertCount(3, $requests);
        $this->assertSame([10_000, 10_000, 1], array_map(static fn (array $payload): int => count($payload['urlList']), $requests));
        $this->assertSame($urls, array_merge(...array_column($requests, 'urlList')));
    }

    public function test_all_sends_the_sitemap_locs(): void
    {
        $this->ready();
        $this->withSitemap(['http://localhost', '/faq', '/payment-proof?page=2']);

        $this->artisan('seo:indexnow', ['--all' => true])->assertExitCode(0)->run();

        $this->assertSame(
            [['http://localhost/', 'http://localhost/faq', 'http://localhost/payment-proof?page=2']],
            array_column($this->sent(), 'urlList'),
        );
    }

    public function test_urls_given_with_all_are_sent_once_alongside_the_sitemap(): void
    {
        $this->ready();
        $this->withSitemap(['/faq']);

        $this->artisan('seo:indexnow', ['url' => ['http://localhost/unlisted', 'http://localhost/faq'], '--all' => true])
            ->assertExitCode(0)
            ->run();

        $this->assertSame(
            [['http://localhost/unlisted', 'http://localhost/faq']],
            array_column($this->sent(), 'urlList'),
        );
    }

    public function test_all_with_an_empty_sitemap_sends_nothing(): void
    {
        $this->ready();

        $this->artisan('seo:indexnow', ['--all' => true])
            ->expectsOutputToContain('The sitemap lists no URLs.')
            ->assertExitCode(1)
            ->run();

        Http::assertNothingSent();
    }

    #[DataProvider('acceptedStatuses')]
    public function test_an_accepted_submission_passes(int $status): void
    {
        $this->ready(fake: false);
        Http::fake([self::ENDPOINT => Http::response('', $status)]);

        $this->artisan('seo:indexnow', ['url' => ['http://localhost/faq']])
            ->expectsOutput("PASS {$status}")
            ->assertExitCode(0)
            ->run();
    }

    /** @return iterable<string, array{int}> */
    public static function acceptedStatuses(): iterable
    {
        yield 'OK' => [200];
        yield 'Accepted, key validation pending' => [202];
    }

    /** @return iterable<string, array{Closure, string}> */
    public static function failedChunks(): iterable
    {
        yield 'rejected' => [static fn ($s) => $s->push('Too Many Requests', 429), 'FAIL 429 Too Many Requests'];
        yield 'no connection' => [static fn ($s) => $s->pushFailedConnection('cURL error 6: Could not resolve host: api.indexnow.org (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://api.indexnow.org/IndexNow'), 'FAIL cURL error 6: Could not resolve host: api.indexnow.org'];
        yield 'body broke off' => [static fn ($s) => $s->pushResponse(static fn (): never => throw new RequestException(new Response(new Psr7Response(503, [], 'down')))), 'FAIL 503 down'];
    }

    #[DataProvider('failedChunks')]
    public function test_a_failed_chunk_fails_the_run_and_the_rest_are_still_sent(Closure $fail, string $row): void
    {
        $this->ready(fake: false);
        $fail(Http::fakeSequence())->push('', 200);

        $this->artisan('seo:indexnow', ['url' => array_map(static fn (int $i): string => "http://localhost/p/{$i}", range(1, 10_001))])
            ->expectsOutput($row)
            ->expectsOutput('PASS 200')
            ->assertExitCode(1)
            ->run();
    }

    /** @param array<string, mixed> $site */
    private function ready(array $site = [], bool $fake = true): void
    {
        if ($fake) {
            Http::fake();
        }

        $this->withSite(['index_now_key' => self::KEY, ...$site]);
    }

    /** @return list<array<string, mixed>> */
    private function sent(): array
    {
        return array_values(Http::recorded()->map(static fn (array $pair): array => json_decode($pair[0]->body(), true, flags: JSON_THROW_ON_ERROR))->all());
    }
}
