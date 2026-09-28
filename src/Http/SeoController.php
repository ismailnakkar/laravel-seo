<?php

declare(strict_types=1);

namespace Seo\Http;

use Illuminate\Container\Container;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Seo\HostRole;
use Seo\Memo;
use Seo\Seo;
use Throwable;

/** @internal Cache headers set here, on success only: SetCacheHeaders would also cache the fail-open robots.txt. */
final class SeoController
{
    /** @internal */
    public const string INDEX_NOW_KEY = '/^[A-Za-z0-9-]{8,128}$/D';

    private const string FALLBACK = "User-agent: *\nDisallow:\n";

    private const string TEXT = 'text/plain; charset=UTF-8';

    public function robots(Request $request, Seo $seo): Response
    {
        try {
            $site = $seo->site($request);
            $body = $site->robotsTxt($site->roleOf($request->getHost()), $seo->sitemapUrl($site));
        } catch (Throwable $e) {
            // A 5xx robots.txt means disallow-all (RFC 9309). Allow-all, uncached, until fixed.
            Container::getInstance()->make(Memo::class)->report($e);

            return new Response(self::FALLBACK, 200, ['Content-Type' => self::TEXT, 'Cache-Control' => 'no-store']);
        }

        return $this->cached($request, new Response($body, 200, ['Content-Type' => self::TEXT]));
    }

    /** Not fail-open: a broken sitemap resolver is a normal 500. */
    public function sitemap(Request $request, Seo $seo, ?string $n = null): Response|RedirectResponse
    {
        $site = $seo->site($request);
        $name = $n === null ? 'sitemap.xml' : "sitemap-{$n}.xml";

        if ($site->roleOf($request->getHost()) !== HostRole::index) {
            return new RedirectResponse($site->to("/{$name}"), 301);
        }

        $xml = $seo->sitemapFile($name, $request);

        abort_if($xml === null, 404);

        return $this->cached($request, new Response($xml, 200, ['Content-Type' => 'application/xml']));
    }

    public function indexNowKey(Request $request, Seo $seo): Response
    {
        $key = $seo->site($request)->indexNowKey;

        abort_unless($key !== null && preg_match(self::INDEX_NOW_KEY, $key) === 1, 404);

        return $this->cached($request, new Response($key, 200, ['Content-Type' => self::TEXT]));
    }

    /** Google reads only max-age and the ETag; private keeps a shared cache from serving a stale file. */
    private function cached(Request $request, Response $response): Response
    {
        $response->setPrivate()->setMaxAge(3600)->setEtag(hash('xxh128', (string)$response->getContent()));
        $response->isNotModified($request);

        return $response;
    }
}
