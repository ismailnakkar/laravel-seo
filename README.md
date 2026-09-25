# Laravel SEO

Head tags, robots.txt, sitemaps and IndexNow for a Laravel app, driven by one config file. You describe
the site in `config/seo.php`, and each page in its own Blade view. Every URL the package emits (canonical,
hreflang, `og:url`, sitemap locs) is built on your site's origin, never on the request host. It also ships
crawler-style test assertions and `seo:check`, a live audit.

## Install

Requires PHP 8.4+ and Laravel 12.61.1+ or 13.12+.

```bash
composer require ismailnakkar/laravel-seo
php artisan seo:install
```

`seo:install` publishes `config/seo.php` and deletes `public/robots.txt`, `public/sitemap.xml` and `public/indexnow-key.txt`,
which the web server would serve instead (it asks first, except for Laravel's stock robots.txt). Commit the deletion, and
delete your own robots.txt and sitemap routes. An nginx `location = /robots.txt` block needs `try_files $uri
/index.php?$query_string;`, or Laravel's body goes out with a 404. `APP_URL`, or `url`, must be the public origin.

Render the head at the top of `<head>`, after charset and viewport, and delete the layout's own `<title>`,
description, robots, canonical and Open Graph tags:

```blade
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<x-seo::head />
```

## Configuration

Every key is optional, and a blank value counts as unset, except in `routes`, where it turns the routes
off. A malformed `url` or `disallow` entry throws. To change the markup, run
`php artisan vendor:publish --tag=seo-views`.

| Key | Default | What it does |
|---|---|---|
| `name` | `app.name` | Title suffix, `og:site_name`, and the home page's Organization and WebSite name. |
| `url` | `app.url` | The origin every URL is built on: an absolute http(s) origin. |
| `title_separator` | `' · '` | Between the page title and `name`. |
| `index_by_default` | `true` | `false`: a page with no `@seo` or `page()` call renders `noindex, follow`. |
| `image` | `null` | Share image (`og:image`, `twitter:card`) unless the page sets one: a URL or a path on `url`, ideally a 1200×630 PNG, JPEG or WebP. Its alt is `name`. |
| `logo` | `null` | Organization logo on the home page: a URL or path, at least 112×112. |
| `organization_type` | `'Organization'` | The home page's schema.org Organization subtype, such as `OnlineBusiness`. |
| `alternate_names` | `[]` | Organization and WebSite `alternateName`. |
| `same_as` | `[]` | Organization `sameAs`: your official profile URLs. |
| `google_verification` | `SEO_GOOGLE_VERIFICATION` | The `google-site-verification` meta, on the home page. |
| `disallow` | `[]` | robots.txt path prefixes, blocked on every host. |
| `noindex_hosts` | `[]` | Hosts or URLs served `noindex, nofollow`. See [Hosts](#hosts). |
| `sitemap` | `[]` | Route names, paths or URLs to list. See [Sitemaps](#sitemaps). |
| `index_now_key` | `INDEXNOW_KEY` | See [IndexNow](#indexnow). |
| `routes` | `true` | Serve `/robots.txt`, `/sitemap.xml`, `/sitemap-{n}.xml` and `/indexnow-key.txt`. `false`: you serve them, and `seo:indexnow` needs your key route named `seo.indexnow`. |
| `locales` | `[]` | Languages, `code => name` in that language; the first is the default. See [Languages](#languages). |
| `user_locale` | `null` | The signed-in user's attribute holding their language. `null`: the session only. |
| `entry_redirect` | `[]` | Route names whose default copy sends a visitor arriving from outside to their language. |

## Pages

Every page is indexable by default. Describe it in its view, where the content is: the title and description
come from `@section('title')` and `@section('description')`, or in a component layout from the head's props,
`<x-seo::head :title="$title ?? null" :description="$description ?? null" />`, and `@seo` sets the rest:

```blade
@section('title', $post->title)
@section('description', $post->excerpt)

@seo(image: $post->cover_url)
```

`@seo` takes the parameters below, `title` and `description` included, and works anywhere in the page's
views: before or after the head, or in a partial. Call `$seo->page()` in a controller, with `\Seo\Seo $seo`
injected, only for a value the view does not have. The calls merge: each non-blank argument replaces its
field, so the later call wins (a view's `@seo` runs after its controller), a blank one never erases, and
`jsonLd` nodes append. A title or description set this way beats a prop, which beats a section.

| Parameter | Default | Effect |
|---|---|---|
| `title` | prop, section, `name` | `<title>` and `og:title`, suffixed with `title_separator` and `name`. |
| `description` | prop, section, none | The meta description and `og:description`. |
| `image` | config `image` | This page's `og:image`, a URL or a path on `url`. Its alt is the page title. |
| `canonical` | the request path on `url` | An absolute http(s) URL or, best, a root-relative path, which lands on `url` whatever host served the request. Replaces the canonical and drops hreflang; on a noindex page, sets `og:url` only. |
| `robots` | `Robots::index` | `Robots::index` (`max-image-preview:large`), `Robots::noindex` (`noindex, follow`) or `Robots::none` (`noindex, nofollow`). In a view, write `\Seo\Robots::noindex`. |
| `paginated` | `false` | Keep `?page=N` (N ≥ 2) in the canonical. |
| `suffixSiteName` | `true` | `false` leaves the title unsuffixed, such as a home page's. |
| `jsonLd` | `[]` | schema.org nodes, arrays or any `JsonSerializable` such as a spatie/schema-org type, one script each. The home page also gets Organization and WebSite. |

- **The head is filled into the HTTP response**, replacing the comment `<x-seo::head />` prints; a call after
  that, such as in middleware after `$next()`, is lost. HTML rendered to a string and sent later or never (a
  string cache's hits, a mail, a PDF, `artisan down --render`) keeps the comment: cache the response in route
  middleware, which still sees the comment on an error page thrown inside a route. A published head view
  renders after the page: it gets only the head's variables, no sections, stacks or component attributes.
- **A section must exist when the head renders**, as a child view's does under `@extends`. `@seo` has no
  such limit.
- **A paginated listing** passes `paginated: true` and answers 404 past its last page. Every other query
  parameter is dropped from the canonical.
- **Error pages** (4xx, 5xx) render noindex and ignore `page()` and `@seo`, the error view's own included: title
  an error view with `@section('title')`.
- **Utility pages** use `Robots::noindex`: password reset, email verification, and any URL with a token in
  its path. A page linking to user-submitted URLs, such as a link interstitial, uses `Robots::none`.

## Sitemaps

List pages in config, such as `'sitemap' => ['home', 'pricing', '/about']`. Each value is a route name,
a path, or an absolute URL on the site's host; anything else throws. For entries from the database,
register a resolver in a service provider's `boot(Seo $seo)`, yielding one `Seo\SitemapEntry` per page:

```php
$seo->sitemapUsing(function (): iterable {
    foreach (Post::query()->select(['slug', 'updated_at'])->lazy() as $post) {
        yield new SitemapEntry("/blog/{$post->slug}", $post->updated_at);
    }
});
```

- Resolver entries come first, and one with the same URL as a config value replaces it.
- Set `lastModified` only to the content's real last change, never `now()`.
- `/sitemap.xml` is built per request: one urlset up to 50,000 URLs, past that an index of `/sitemap-{n}.xml`.
- With nothing configured, `/sitemap.xml` answers 404 and robots.txt names no sitemap.

## robots.txt

With `'disallow' => ['/admin/']` and a sitemap, the site's host serves:

```
User-agent: *
Disallow: /admin/

Sitemap: https://example.com/sitemap.xml
```

- End each `disallow` prefix in `/`: `/admin` also blocks `/administrators`. A disallowed page can still
  be indexed from links; to keep one out, give it `Robots::noindex` and leave it crawlable.
- robots.txt answers 200 during `artisan down`, and serves allow-all, uncached, if your config throws.

## Hosts

- Pages on any other host the app answers on canonicalise to `url`, and only `url` advertises the sitemap.
- Responses on `noindex_hosts`, such as `env('DOWNLOAD_URL')`, carry `X-Robots-Tag: noindex, nofollow` and
  a matching robots meta, and stay crawlable so the noindex is read. nginx serves static files itself, so
  set the header there too: `map $host $seo_noindex { dl.example.com "noindex, nofollow"; default ""; }`
  in the http block, and `add_header X-Robots-Tag $seo_noindex always;` in the server block.
- Redirect www and alias hosts to `url` with one 301 that keeps path and query: at the edge (a Cloudflare
  redirect rule or nginx), or in a global middleware.
- On Laravel 13, a route inside `Route::domain()` matches before the package's on that host (on 12, after it),
  so keep a domain catch-all from matching a dot: `->where('slug', '[^.]+')`.

## Languages

```php
// config/seo.php
'locales' => ['en' => 'English', 'fr' => 'Français', 'es' => 'Español'], // the first is the default
'user_locale' => 'locale',    // the users' column; null keeps the choice in the session only
'entry_redirect' => ['home'], // these pages send a visitor arriving from outside to their language

// routes/web.php
Route::localized(function () {
    Route::get('/', HomeController::class)->name('home');
    Route::get('terms', [PageController::class, 'terms'])->name('terms');
});
```

`/terms` is English and `/fr/terms` French. A copy always renders its URL's language. `route('terms')` follows the
current language, every copy emits the same hreflang set with x-default, and a sitemap entry expands to every locale's
URL.

Every other page renders the visitor's choice. That is their account's `user_locale`, else the session, else the
language of the first `/fr/…` page of their visit, else the browser's, else the default. Visiting a page never
changes it; the switcher does:

```blade
@inject('seo', \Seo\Seo::class)
<form method="POST" action="{{ route('seo.locale') }}">
    @csrf
    <input type="hidden" name="to" value="{{ request()->getRequestUri() }}">
    @foreach ($seo->languages() as $language)
        <button name="locale" value="{{ $language->code }}" lang="{{ $language->code }}" @if ($language->current) aria-current="true" @endif>{{ $language->name }}</button>
    @endforeach
</form>
```

It saves the choice, to the account too, and returns the visitor to the same page in that language. A signed page
is signed again only while its own signature is still valid under the current key, and only when the re-signed URL
is that page's copy in the chosen language on the same host; otherwise the visitor returns to the page unchanged.

- Call `Route::localized()` outside any prefix group, before catch-all and fallback routes. Inside, prefix with
  `Route::prefix()->group()`, never a route-level `->prefix()`.
- Put in only pages whose content is translated, plus their forms' POST routes.
- Link with `route()`: `url()` and hard-coded paths go to the default copy.
- On `/fr/terms`, `Route::currentRouteName()`, `Route::is()` and `routeIs()` see `terms`. `route:list` still shows
  `seo.fr.terms`.
- An account without a language takes the page's when it signs up or signs in, so `/fr/register` saves `fr`. Keep
  `HasLocalePreference` on the User so mail goes out in that language; its links carry the language's prefix.
- An existing account with an empty `user_locale` gets the visitor's choice on its next visit, as a normal model save
  (model events fire).
- `POST /locale` is registered before your routes. On Laravel 13, a catch-all inside `Route::domain()` that accepts
  POST would take it.
- A CDN must not cache the HTML of `entry_redirect` pages: they vary per visitor. Crawlers are never redirected.
- A 404 for a URL no route matches renders in the default language unless you have a `Route::fallback()`.
- Livewire: add `\Seo\Http\ResolveLocale::class` to `Livewire::addPersistentMiddleware()`. Otherwise a component
  update renders in the saved language, not the page's.
- To 301 old query-parameter URLs (`/terms?lang=fr` to `/fr/terms`), redirect to
  `\Seo\LocalizedRoute::of($request->route())?->path($request->getPathInfo(), $code)`. Check `$code` against your
  codes first: `?lang=/evil.test` would otherwise redirect off-site.
- Your layout renders `<html lang>` from `app()->getLocale()`.
- `seo:check` reports an `entry_redirect` name that is not a localized route, and a `user_locale` the users table
  lacks.
- Remove any locale middleware of your own: it runs after the package's and would override a copy's language.
  `assertHreflangReciprocal()` catches a copy that renders another language.

## Upgrading from 0.2

- Move the codes into `seo.locales` as `code => name`, default first, and drop the `Locales` argument:
  `Route::localized(function () { … })`.
- `Seo\Http\SetLocale` is gone. Remove any locale middleware of your own: it would override a copy's language.
- A localized copy answers to the route's own name: `terms` on `/fr/terms`, no longer `seo.fr.terms`.
- New dependency: `jaybizzle/crawler-detect`.
- Set `seo.locales` first: with fewer than two, `Route::localized()` registers plain routes, so every `/fr/…` URL
  would 404.

## IndexNow

Set `INDEXNOW_KEY` to 8 to 128 letters, digits or dashes, such as `bin2hex(random_bytes(16))`;
`/indexnow-key.txt` serves it. After a deploy, submit what changed, as paths or URLs on the site's host:

```bash
php artisan seo:indexnow /pricing /blog/new-post
php artisan seo:indexnow --all   # every sitemap URL: only after a migration or redesign
```

## Testing

Use the `Seo\Testing\SeoAssertions` trait. Every helper fetches through the kernel as Googlebot without
cookies, so `actingAs()` neither reaches a helper nor survives one. Assert head tags on a response
(`$this->get()`): `$this->view()` and `$this->blade()` see only the comment.

```php
$this->assertSitemapComplete();
$this->assertTrue($this->robotsTxt('example.com')->allows('Googlebot', '/pricing'));
```

| Method | Asserts |
|---|---|
| `robotsTxt($host)` | `http://{host}/robots.txt` answers 200 `text/plain` with the body your config renders. Returns a matcher whose `allows($token, $path)` returns a bool. |
| `followRedirectChain($url, $maxHops = 10, $userAgent = Googlebot, $headers = [])` | Follows redirects as Googlebot, or `$userAgent`, failing on a loop or past `$maxHops`. Returns the last response. |
| `assertCrawlable($url, $maxHops = 3)` | 200, not noindex, one `<title>` and one self-referencing canonical in `<head>`, no SVG `og:image`. |
| `assertNotIndexable($response)` | The `X-Robots-Tag` or robots meta says noindex. |
| `assertNoindexNotDisallowed(...$urls)` | Each URL is noindex and allowed by robots.txt, so the noindex is read. |
| `assertNoStaticShadows(...$extraPaths)` | `public/robots.txt`, `public/sitemap.xml`, `public/indexnow-key.txt` and the extra paths do not exist. |
| `assertSitemapComplete()` | Every sitemap URL is on the site's host, unique, allowed by robots.txt and crawlable; titles and descriptions are unique per language; the home page's CSS, JS, `og:image` and logo are allowed. Fails with no sitemap. |
| `assertHreflangReciprocal($url)` | Every alternate answers 200, is self-canonical, lists the same set and renders its own `<html lang>`, even under a conflicting `Accept-Language`. |

## seo:check

Run it against the deployed site: it fetches `https://{host}`, `https://www.{host}` and `http://{host}` as a
crawler does, without cookies or following redirects, so it cannot check `artisan serve`. It exits 1 on any
FAIL. It judges against your local config, so run it with production's values and config uncached:

```bash
APP_URL=https://example.com php artisan seo:check https://example.com https://dl.example.com --link=https://example.com/go/abc
docker compose exec -u sail -e APP_URL=https://example.com laravel.test php artisan seo:check https://example.com  # Sail
```

| Row | Checks |
|---|---|
| `entry_redirect` | Each name is a `Route::localized()` route. |
| `user_locale` | The users table has the column. An unreachable database WARNs. |
| `robots.txt` | 200 `text/plain`, byte-identical to the app's body. A FAIL names the first differing line. |
| `sitemap` | XML whose URLs are all on the host and allowed by robots.txt. |
| `sample`, `descriptions` | `--sample` sitemap URLs (default 5) are 200, self-canonical and indexable, with no second `<title>`. One without a description WARNs. |
| `home` | WebSite JSON-LD carries `name`, and the logo loads. A title without `name`, or `name` left at Laravel's default `Laravel`, WARNs. |
| `crawlers` | AI search agents (OAI-SearchBot, Claude-SearchBot, PerplexityBot…) get Chrome's 2xx. Indicative only: the user agent is spoofed. |
| `http`, `www` | `http://` and `www.` answer one 301 or 308 to the right host. |
| `x-robots-tag` | A noindex host's `/` carries noindex. A static file without it WARNs: see [Hosts](#hosts). |
| `cookieless` | Each `--link` reaches 200 without cookies. More than 3 hops WARNs. |

## Overrides

For values a config file cannot hold, such as settings an admin edits, return config overrides from a
closure in a service provider's `boot(Seo $seo)`. Its parameters are injected, as are `sitemapUsing()`'s.

```php
$seo->siteUsing(fn (Settings $settings): array => ['name' => $settings->siteName(), 'image' => $settings->shareImage()]);
```

Omitted or null keys keep their config values; `sitemap` and `routes` always come from config. It runs
on every request, so cache what it reads, and in the console, so never read the request.

## Livewire and Inertia

A Livewire full-page component uses `@seo` in its view or `$seo->page()` in `mount()`, and `#[Title]` reaches
the head through `<x-seo::head :title="$title ?? null" />`. Inertia gets the server-rendered head in
`app.blade.php` only: call `$seo->page()` in the controller; client-side `<Head>` tags are yours.

## Not included

- llms.txt and Markdown page variants: Google Search ignores them.
- `changefreq`, `priority`, sitemap pings, `rel=next/prev` and meta keywords: Google and Bing ignore them.
- Snippet limits (`nosnippet`, `max-snippet`, `noarchive`): they only take visibility away.
- `og:locale` and `twitter:*` tags beyond `twitter:card`: no search effect, and X falls back to Open Graph.
- Bing's `msvalidate.01` (verify Bing by importing the site from Search Console) and subdirectory installs:
  `url` must be a bare origin.
- Typed schema.org helpers (pass `jsonLd` nodes), www and alias-host redirects, translated slugs and a queued
  IndexNow job.
