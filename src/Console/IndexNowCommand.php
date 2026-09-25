<?php

declare(strict_types=1);

namespace Seo\Console;

use GuzzleHttp\Exception\TransferException;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Routing\Router;
use Seo\Http\SeoController;
use Seo\Seo;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'seo:indexnow')]
final class IndexNowCommand extends Command
{
    /** Shared endpoint: every participating engine gets the submission. */
    private const string ENDPOINT = 'https://api.indexnow.org/IndexNow';

    /** The protocol's URL limit per request. */
    private const int CHUNK = 10_000;

    protected $signature = 'seo:indexnow {url?*} {--all}';

    protected $description = 'Submit the URLs that changed to IndexNow (--all only after a migration or redesign)';

    public function handle(Seo $seo, Router $router, Factory $http): int
    {
        $urls = $this->argument('url');

        if ($urls === [] && ! $this->option('all')) {
            $this->fail('Pass the URLs that changed, or --all after a migration or redesign.');
        }

        $site = $seo->site();
        $key = (string)$site->indexNowKey;

        if (preg_match(SeoController::INDEX_NOW_KEY, $key) !== 1) {
            $this->fail('Set seo.index_now_key (INDEXNOW_KEY) to 8 to 128 letters, digits or dashes.');
        }

        if (! $router->has('seo.indexnow')) {
            $this->fail('The key file route is not registered: set seo.routes to true, or name your own key route seo.indexnow.');
        }

        if ($this->option('all')) {
            foreach ($seo->sitemap() as $entry) {
                $urls[] = $entry->loc;
            }

            $urls = array_values(array_unique($urls));
        }

        if ($urls === []) {
            $this->fail('The sitemap lists no URLs.');
        }

        // The key file vouches for its own host only; one foreign URL fails the whole request.
        $offHost = array_filter($urls, static fn (string $url): bool => strtolower((string)parse_url($url, PHP_URL_HOST)) !== $site->host());

        if ($offHost !== []) {
            $this->fail("Not on {$site->host()}, the host the key file verifies: " . implode(', ', $offHost));
        }

        // All bodies encoded before sending: an unencodable URL sends nothing.
        $bodies = array_map(static fn (array $chunk): string => json_encode([
            'host'        => $site->host(),
            'key'         => $key,
            'keyLocation' => $site->to('/indexnow-key.txt'),
            'urlList'     => $chunk,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), array_chunk($urls, self::CHUNK));

        $exitCode = self::SUCCESS;

        foreach ($bodies as $body) {
            try {
                $response = $http->withBody($body, 'application/json; charset=utf-8')->post(self::ENDPOINT);
            } catch (RequestException $e) { // a 4xx/5xx whose body broke off
                $response = $e->response;
            } catch (ConnectionException|TransferException $e) {
                // Before Laravel 13.26, PendingRequest wraps only ConnectException and RequestException.
                $this->line('FAIL ' . (strstr($e->getMessage(), ' (see ', true) ?: $e->getMessage()));
                $exitCode = self::FAILURE;

                continue;
            }

            if (in_array($response->status(), [200, 202], true)) {
                $this->line("PASS {$response->status()}");
            } else {
                $this->line("FAIL {$response->status()} {$response->body()}");
                $exitCode = self::FAILURE;
            }
        }

        return $exitCode;
    }
}
