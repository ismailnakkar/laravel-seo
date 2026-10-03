# Laravel SEO

Head tags, canonical URLs, robots.txt, sitemaps and IndexNow for Laravel, driven by `config/seo.php`. Every URL it
emits is built on your site's origin, never the request host. Ships crawler-style test assertions and `seo:check`, a
live audit.

Requires PHP 8.4+ and Laravel 12.61.1+ or 13.12+. See [UPGRADE.md](UPGRADE.md) and [CHANGELOG.md](CHANGELOG.md).

## Install

```bash
composer require ismailnakkar/laravel-seo
php artisan seo:install
```

`seo:install` publishes the config and deletes `public/robots.txt`, `public/sitemap.xml` and `public/indexnow-key.txt`,
which the web server would serve instead of the package's routes. Set `APP_URL` (or `seo.url`) to the public origin.
An nginx `location = /robots.txt` block needs `try_files $uri /index.php?$query_string;`, or robots.txt answers 404.

The package registers `GET /robots.txt`, `/sitemap.xml`, `/sitemap-{n}.xml` and `/indexnow-key.txt` (an app route on
the same URI wins; `'routes' => false` turns them off), a global `NoindexHosts` middleware, `<x-seo::head />`, `@seo`,
and the `seo:install`, `seo:check` and `seo:indexnow` commands.

## Usage

Put the head at the top of your layout's `<head>`, and remove the layout's own title, description, robots, canonical
and Open Graph tags:

```blade
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<x-seo::head />
```

Describe each page in its view:

```blade
@section('title', $post->title)
@section('description', $post->excerpt)

@seo(image: $post->cover_url)
```

In a component layout, pass them as props instead: `<x-seo::head :title="$title ?? null" :description="$description ?? null" />`.
From a controller, inject `Seo\Seo` and call `$seo->page(...)` with the same arguments.

| Parameter | Default | Effect |
|---|---|---|
| `title` | prop, section, `name` | `<title>` and `og:title`, suffixed with `title_separator` and `name`. |
| `description` | prop, section | Meta and `og:description`. |
| `image` | config `image` | `og:image`: a URL or a path on `url`. |
| `canonical` | request path on `url` | Replaces the canonical and drops hreflang. Prefer a root-relative path. |
| `robots` | `Robots::index` | `Robots::noindex` (`noindex, follow`) or `Robots::none` (`noindex, nofollow`). In a view, write `\Seo\Robots::noindex`. |
| `paginated` | `false` | Keep `?page=N` (N ≥ 2) in the canonical. |
| `suffixSiteName` | `true` | `false` leaves the title unsuffixed. |
| `jsonLd` | `[]` | schema.org nodes (arrays or `JsonSerializable`). The home page also gets Organization and WebSite. |

Calls merge: each non-blank argument replaces its field, so a view's `@seo` beats its controller's `page()`, and
`jsonLd` appends.

Good to know:

- `<x-seo::head />` prints a marker that is filled into the HTTP response, so `@seo` works anywhere in the views. A
  call after the response is built (middleware after `$next()`) is lost, and HTML rendered to a string (mail, PDF,
  `artisan down --render`, `$this->view()` in tests) keeps the marker.
- Error responses (4xx, 5xx) render `noindex` and ignore `@seo`.
- Use `Robots::noindex` on utility pages (password reset, email verification) and `Robots::none` on pages linking to
  user-submitted URLs.
- If the site config cannot be built (a throwing `siteUsing()`, an invalid `url`), it throws outside production. In
  production the error is reported and pages are served with only a `<title>` and `noindex, nofollow`, uncached.
- Customise the markup with `php artisan vendor:publish --tag=seo-views`.
- Livewire: `@seo` in the view or `$seo->page()` in `mount()`. Inertia: call `$seo->page()` in the controller.

## Sitemaps

List route names, paths or URLs in config: `'sitemap' => ['home', 'pricing', '/about']`. For database entries,
register a resolver in a service provider's `boot(Seo $seo)`:

```php
$seo->sitemapUsing(function (): iterable {
    foreach (Post::query()->select(['slug', 'updated_at'])->lazy() as $post) {
        yield new SitemapEntry("/blog/{$post->slug}", $post->updated_at);
    }
});
```

Resolver entries come first and replace a config entry with the same URL. Set `lastModified` to the content's real
last change, never `now()`. Past 50,000 URLs `/sitemap.xml` becomes an index of `/sitemap-{n}.xml`. With nothing
listed, `/sitemap.xml` is a 404.

Sitemap responses are `private` and nothing is cached server-side: every `/sitemap-{n}.xml` request runs the resolver from
the start up to chunk `n`. The last chunk and the `/sitemap.xml` index need a full pass over every entry, so the
resolver runs to the end for them. If the resolver queries the database, cache its rows in the closure.

## robots.txt

```
User-agent: *
Disallow: /admin/

Sitemap: https://example.com/sitemap.xml
```

Built from `disallow` (end each prefix in `/`). Only the `url` host lists the sitemap. It keeps answering 200 during
`artisan down`, and serves allow-all if your config throws.

## Hosts

- Other hosts the app answers on canonicalise to `url`. Redirect www and alias hosts to `url` with a 301 yourself.
- `noindex_hosts` responses carry `X-Robots-Tag: noindex, nofollow`. nginx serves static files without PHP, so add the
  header there too: `map $host $seo_noindex { dl.example.com "noindex, nofollow"; default ""; }` in `http`, and
  `add_header X-Robots-Tag $seo_noindex always;` in `server`.
- On Laravel 13, a `Route::domain()` route matches before the package's; keep a domain catch-all off dots with
  `->where('slug', '[^.]+')`.

## IndexNow

Set `INDEXNOW_KEY` to 8–128 letters, digits or dashes. After a deploy, submit what changed:

```bash
php artisan seo:indexnow /pricing /blog/new-post
php artisan seo:indexnow --all   # every sitemap URL, only after a migration or redesign
```

## Overrides

For values a config file cannot hold, such as admin-edited settings, return overrides from a closure in
`boot(Seo $seo)`. Its parameters are injected. It runs on every request and in the console, so cache what it reads and
never read the request.

```php
$seo->siteUsing(fn (Settings $settings): array => ['name' => $settings->siteName()]);
```

Null keys keep their config value; `sitemap` and `routes` always come from config.

## Multilingual sites

[ismailnakkar/laravel-localization](https://github.com/ismailnakkar/laravel-localization) plugs in canonicals,
hreflang and sitemap alternates with no configuration. For any other setup, register a closure that returns, for a
matched route and its path, the page's own path and each language's path:

```php
$seo->alternatesUsing(function (\Illuminate\Routing\Route $route, string $path): ?array {
    if (! in_array('locale', $route->parameterNames(), true)) {
        return null; // not localized
    }

    $rest = preg_replace('~^/[^/]+~', '', $path);          // /fr/terms → /terms
    $code = rawurldecode(explode('/', $path)[1]);         // /%66r/terms → fr

    return [
        'path' => "/{$code}{$rest}",
        'alternates' => collect(['en', 'fr', 'es'])->mapWithKeys(fn ($c) => [$c => "/{$c}{$rest}"])->all(),
    ];
});
```

`alternates` is in hreflang order, default language first (it becomes `x-default`). Return paths only: a scheme, `?`
or `#` throws. Pages with a `canonical` override or served noindex emit no alternates. For sitemap URLs the route is
matched but not bound: read `$path`, never `$route->parameter()`.

## Testing

Use the `Seo\Testing\SeoAssertions` trait. Helpers fetch through the kernel as Googlebot without cookies, so
`actingAs()` neither reaches a helper nor survives one.

```php
$this->assertSitemapComplete();
$this->assertTrue($this->robotsTxt('example.com')->allows('Googlebot', '/pricing'));
```

| Method | Asserts |
|---|---|
| `robotsTxt($host)` | robots.txt answers 200 with your config's body. Returns a matcher with `allows($token, $path)`. |
| `followRedirectChain($url, $maxHops = 10, $userAgent = Googlebot, $headers = [])` | Follows redirects, failing on a loop or too many hops. Returns the last response. |
| `assertCrawlable($url, $maxHops = 3)` | 200, indexable, one `<title>`, one self-referencing canonical, no SVG `og:image`. |
| `assertNotIndexable($response)` | `X-Robots-Tag` or robots meta says noindex. |
| `assertNoindexNotDisallowed(...$urls)` | Each URL is noindex and allowed by robots.txt. |
| `assertNoStaticShadows(...$extraPaths)` | `public/robots.txt`, `public/sitemap.xml`, `public/indexnow-key.txt` and any extra full paths do not exist. |
| `assertSitemapComplete()` | Every sitemap URL is on-host, unique, allowed and crawlable, with unique titles and descriptions per language. |
| `assertHreflangReciprocal($url)` | Every alternate answers 200, is self-canonical, lists the same set and renders its own `<html lang>`. |

## seo:check

Audits the deployed site as a crawler does: robots.txt, sitemap, sample pages, the home page's JSON-LD and logo, AI
crawler access, `http://` and `www.` redirects, `X-Robots-Tag` on noindex hosts, and cookieless `--link` chains. It
exits 1 on any FAIL. It compares against local config, so run it with production's values:

```bash
APP_URL=https://example.com php artisan seo:check https://example.com https://dl.example.com --link=https://example.com/go/abc
```

## Configuration

Every key in `config/seo.php` is optional; a blank value counts as unset, except in `routes`, where it turns the routes
off.

| Key | Default | |
|---|---|---|
| `name` | `app.name` | Title suffix, `og:site_name`, Organization and WebSite name. |
| `url` | `app.url` | The origin every URL is built on. |
| `title_separator` | `' · '` | Between the page title and `name`. |
| `index_by_default` | `true` | `false`: pages without `@seo` render `noindex, follow`. |
| `image` | `null` | Default share image, ideally 1200×630. |
| `logo` | `null` | Organization logo on the home page, at least 112×112. |
| `organization_type` | `'Organization'` | schema.org subtype, such as `OnlineBusiness`. |
| `alternate_names` | `[]` | Organization and WebSite `alternateName`. |
| `same_as` | `[]` | Organization `sameAs` profile URLs. |
| `google_verification` | `SEO_GOOGLE_VERIFICATION` | `google-site-verification` meta on the home page. |
| `disallow` | `[]` | robots.txt path prefixes. |
| `noindex_hosts` | `[]` | Hosts served `noindex, nofollow`. |
| `sitemap` | `[]` | Route names, paths or URLs. |
| `index_now_key` | `INDEXNOW_KEY` | The IndexNow key. |
| `routes` | `true` | `false`: serve the files yourself (name your key route `seo.indexnow`). |

## Not included

llms.txt, `changefreq`/`priority`, meta keywords, `rel=next/prev`, snippet limits, `twitter:*` beyond `twitter:card`,
typed schema.org helpers, host redirects and subdirectory installs.

## License

MIT. See [LICENSE](LICENSE).
