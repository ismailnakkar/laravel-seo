# Laravel SEO

SEO and multilingual routing for a Laravel app, driven by one config file, in two parts:

- **SEO:** head tags, robots.txt, sitemaps and IndexNow. You describe the site in `config/seo.php`, and each page in
  its own Blade view. Every URL the package emits (canonical, hreflang, `og:url`, sitemap locs) is built on your site's
  origin, never on the request host. Crawler-style test assertions and `seo:check`, a live audit, check the result.
- **Languages** (optional): `Route::localized()` serves a page once per language (`/terms`, `/fr/terms`) with
  hreflang and sitemap entries for every copy. The package picks each visitor's language, remembers it, and gives you a
  switcher.

Requires PHP 8.4+ and Laravel 12.61.1+ or 13.12+.

Upgrading? Follow [UPGRADE.md](UPGRADE.md). Changes are listed in [CHANGELOG.md](CHANGELOG.md).

## Install

```bash
composer require ismailnakkar/laravel-seo
php artisan seo:install
```

`seo:install` publishes `config/seo.php` and deletes `public/robots.txt`, `public/sitemap.xml` and `public/indexnow-key.txt`,
which the web server would serve instead (it asks first, except for Laravel's stock robots.txt). Commit the deletion, and
delete your own robots.txt and sitemap routes. An nginx `location = /robots.txt` block needs `try_files $uri
/index.php?$query_string;`, or Laravel's body goes out with a 404. `APP_URL`, or `url`, must be the public origin.

### What it registers

- `GET /robots.txt`, `/sitemap.xml`, `/sitemap-{n}.xml` and `/indexnow-key.txt`, before your routes, so one of your
  own on the same URI wins. `routes => false` turns them off. robots.txt keeps answering during `artisan down`.
- `NoindexHosts`, a global middleware that adds `X-Robots-Tag` on `noindex_hosts`.
- `<x-seo::head />`, `@seo`, and a listener that fills the head into the response.
- The `Route::localized()` macro, a URL formatter that makes `route()` follow the current language, and a listener that
  sets a copy's language as its route matches.
- With two or more `locales` and `remember_locale` on: `ResolveLocale` and `ApplyLocale`, appended to `web` and ranked
  (see [Middleware order](#middleware-order)), and `POST /locale`, named `seo.locale`, for the switcher.
- The commands `seo:install`, `seo:check` and `seo:indexnow`.

## Quickstart

**1. Render the head** at the top of `<head>`, after charset and viewport, and delete the layout's own `<title>`,
description, robots, canonical and Open Graph tags:

```blade
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<x-seo::head />
```

**2. Describe each page** in its view (see [Pages](#pages)):

```blade
@section('title', $post->title)
@section('description', $post->excerpt)

@seo(image: $post->cover_url)
```

**3. List the pages to index** in `config/seo.php`: `'sitemap' => ['home', 'pricing', '/about']`.

**4. Check the live site** in your deploy: `php artisan seo:check https://example.com` (see [seo:check](#seocheck)).

Multilingual only:

**5. List the languages**, default first. `user_locale` is optional: your users' column holding each member's language.

```php
// config/seo.php
'locales' => ['en', 'fr', 'es'],
'user_locale' => 'locale',
```

**6. Wrap the translated pages** in `Route::localized()`, and link to them with `route()`:

```php
// routes/web.php
Route::localized(function () {
    Route::get('/', HomeController::class)->name('home');
    Route::get('terms', [PageController::class, 'terms'])->name('terms');
});
```

**7. Render `<html lang="{{ app()->getLocale() }}">`** in your layout, and add [the switcher](#the-switcher).

## Pages

Every page is indexable by default. The title and description come from `@section('title')` and
`@section('description')`, or in a component layout from the head's props,
`<x-seo::head :title="$title ?? null" :description="$description ?? null" />`, and `@seo` sets the rest.

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
  middleware, which still sees the comment on an error page thrown inside a route.
- **To change the markup**, run `php artisan vendor:publish --tag=seo-views`. The published view renders after the
  page: it gets only the head's variables, no sections, stacks or component attributes.
- **A section must exist when the head renders**, as a child view's does under `@extends`. `@seo` has no
  such limit.
- **A paginated listing** passes `paginated: true` and answers 404 past its last page. Every other query
  parameter is dropped from the canonical.
- **Error pages** (4xx, 5xx) render noindex and ignore `page()` and `@seo`, the error view's own included: title
  an error view with `@section('title')`.
- **Utility pages** use `Robots::noindex`: password reset, email verification, and any URL with a token in
  its path. A page linking to user-submitted URLs, such as a link interstitial, uses `Robots::none`.
- **A site that cannot be built**, from a `siteUsing()` closure that throws or an invalid `url` or `disallow`, throws
  outside production. In production the error is reported once per request and the page keeps its status with only its
  `<title>` and `noindex, nofollow`, uncached (a response cache that ignores `Cache-Control`, like
  spatie/laravel-responsecache, keeps it until cleared). Uptime checks still see 200, so run `seo:check` in your deploy
  and alert on reported errors. The sitemap, and your own code that calls `Seo::site()`, answer 500, which Laravel
  reports as well; `$exceptions->dontReportDuplicates()` in `bootstrap/app.php` keeps that to one report. A page's own
  error, such as JSON-LD that cannot be encoded or a published head view that throws, is still a 500; an error page
  keeps its status with the fallback head.

### The head in Livewire and Inertia

A Livewire full-page component uses `@seo` in its view or `$seo->page()` in `mount()`, and `#[Title]` reaches
the head through `<x-seo::head :title="$title ?? null" />`. Inertia gets the server-rendered head in
`app.blade.php` only: call `$seo->page()` in the controller; client-side `<Head>` tags are yours.

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
- An entry on a `Route::localized()` route expands to every language's URL.

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

## IndexNow

Set `INDEXNOW_KEY` to 8 to 128 letters, digits or dashes, such as `bin2hex(random_bytes(16))`;
`/indexnow-key.txt` serves it. After a deploy, submit what changed, as paths or URLs on the site's host:

```bash
php artisan seo:indexnow /pricing /blog/new-post
php artisan seo:indexnow --all   # every sitemap URL: only after a migration or redesign
```

## Overrides

For values a config file cannot hold, such as settings an admin edits, return config overrides from a
closure in a service provider's `boot(Seo $seo)`. Its parameters are injected, as are `sitemapUsing()`'s.

```php
$seo->siteUsing(fn (Settings $settings): array => ['name' => $settings->siteName(), 'image' => $settings->shareImage()]);
```

Omitted or null keys keep their config values; `sitemap` and `routes` always come from config. It runs
on every request, so cache what it reads, and in the console, so never read the request. If it throws, see
[Pages](#pages) for what production serves.

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

Run it against the deployed site, in your deploy: it fetches `https://{host}`, `https://www.{host}` and
`http://{host}` as a crawler does, without cookies or following redirects, so it cannot check `artisan serve`. It
exits 1 on any FAIL. It judges against your local config, so run it with production's values and config uncached:

```bash
APP_URL=https://example.com php artisan seo:check https://example.com https://dl.example.com --link=https://example.com/go/abc
docker compose exec -u sail -e APP_URL=https://example.com laravel.test php artisan seo:check https://example.com  # Sail
```

| Row | Checks |
|---|---|
| `entry_redirect` | Each name is a `Route::localized()` route. WARNs while `remember_locale` is false. |
| `user_locale` | The users table has the column. An unreachable database, or `remember_locale` false, WARNs. |
| `robots.txt` | 200 `text/plain`, byte-identical to the app's body. A FAIL names the first differing line. |
| `sitemap` | XML whose URLs are all on the host and allowed by robots.txt. |
| `sample`, `descriptions` | `--sample` sitemap URLs (default 5) are 200, self-canonical and indexable, with no second `<title>`. One without a description WARNs. |
| `home` | WebSite JSON-LD carries `name`, and the logo loads. A title without `name`, or `name` left at Laravel's default `Laravel`, WARNs. |
| `crawlers` | AI search agents (OAI-SearchBot, Claude-SearchBot, PerplexityBot…) get Chrome's 2xx. Indicative only: the user agent is spoofed. |
| `http`, `www` | `http://` and `www.` answer one 301 or 308 to the right host. |
| `x-robots-tag` | A noindex host's `/` carries noindex. A static file without it WARNs: see [Hosts](#hosts). |
| `cookieless` | Each `--link` reaches 200 without cookies. More than 3 hops WARNs. |

## Languages

`/terms` is English and `/fr/terms` French: each is a copy of the same route. A copy always renders its URL's
language, `route('terms')` follows the current language, every copy emits the same hreflang set with x-default, and a
sitemap entry expands to every language's URL. A page outside `Route::localized()`, such as a members area or link
pages, renders the visitor's language, chosen as [below](#how-the-language-is-chosen).

- Call `Route::localized()` outside any prefix group, before catch-all and fallback routes. Inside, prefix with
  `Route::prefix()->group()`, never a route-level `->prefix()`.
- Put in only pages whose content is translated, plus their forms' POST routes.
- `/en/terms`, with `en` the default, answers a 301 to `/terms` and counts as opening it. Any other `/en/…` path
  still 404s. A route of your own on `/en/…` must come before `Route::localized()`.
- Link with `route()`: `url()` and hard-coded paths go to the default copy, so a click on one switches the visitor to
  the default language.
- On `/fr/terms`, `Route::currentRouteName()`, `Route::is()` and `routeIs()` see `terms`. `route:list` still shows
  `seo.fr.terms`.
- Keep `HasLocalePreference` on the User, returning its `user_locale`, so mail goes out in the account's language. Its
  links carry that language's prefix.
- A 404 for a URL no route matches renders in the default language unless you have a `Route::fallback()`, so a click
  on one of its links switches to the default.
- `assertHreflangReciprocal()` catches a copy that renders another language.

### How the language is chosen

| Page | Language |
|---|---|
| A `Route::localized()` copy | Its URL's, always. |
| Any other page | The one the visitor browses in this session, else the account's, else the browser's (`Accept-Language`), else the default. |
| Mail through `HasLocalePreference` | The account's. |

A new device, an expired session or a remember-me sign-in has browsed nothing yet, so it gets the account's language.

| Event | Session | Account |
|---|---|---|
| Opening a copy in another language than the visitor gets | set | — |
| The switcher, `POST /locale` | set | set, when signed in |
| A signed-in GET page view, counted as for opening, while the account has no language | — | filled once, with the visitor's |
| Anything else | — | — |

**Opening** a copy is a page load or your app's own fetch (Inertia, `wire:navigate`), and from another origin, a
sibling subdomain included, only a top-level GET, such as a search result. A prefetch counts: the browser serves it for
the click. A signed link never opens its copy (its sender chose the language), nor does a Livewire component update
or, in browsers that send Fetch Metadata, an `<img>` or iframe from any site. The default copy counts too, so a click
on the logo to `/` switches to the default. A copy in the language the visitor already gets records nothing, so a new
device that `auth` sends to the login page in the browser's language still gets the account's language once signed in.

**`entry_redirect`**, such as `['home']`, sends a visitor who arrives from outside the site on that page's default copy
to their language's copy with a 302, before anything is recorded. Crawlers, signed links and clicks inside the site
are never redirected. A CDN must not cache these pages' HTML: they vary per visitor. Under Laravel's priority list, a
`throttle` on such a page counts a redirected arrival twice.

### The switcher

```blade
@inject('seo', \Seo\Seo::class)
<form method="POST" action="{{ route('seo.locale') }}">
    @csrf
    <input type="hidden" name="to" value="{{ request()->getRequestUri() }}">
    @foreach ($seo->languages() as $language)
        <button name="locale" value="{{ $language->code }}" lang="{{ $language->code }}" @if ($language->current) aria-current="true" @endif>{{ __("languages.{$language->code}", locale: $language->code) }}</button>
    @endforeach
</form>
```

The labels are your own text, each read in its own language (French from `lang/fr/languages.php`) to match its
`lang`. The switcher saves the language to the session and, when signed in, to the account, then returns the visitor to
the same page in it. A signed page is signed again only while its own signature is still valid under the current key,
and only when the re-signed URL is that page's copy in the chosen language on the same host; otherwise, as for a
`signed:relative` route, the visitor returns to the page unchanged.

- A link to another copy (`<a hreflang>`) switches the session when opened, and so does a hover prefetch of it:
  exclude such links from prefetch, such as with `data-turbo-prefetch="false"`.
- `POST /locale` is registered before your routes. On Laravel 13, a catch-all inside `Route::domain()` that accepts
  POST would take it.

### The account and the language prompt

With `user_locale` naming a column of your users table, each member's language is read from it and saved to it: by the
switcher, and once, on a page view, into an account without one (a value outside `locales` counts as none). Another
guard's model without the column, such as an admin's, is left alone.

The site follows what the member browses; the account does not. After opening `/fr`, a member reads French everywhere
in that browser, while mail, other devices and other hosts keep the account's language. Likewise, a guest who switches
just before signing in keeps that language on the site, and the account keeps its own. To keep the two from drifting,
render this prompt in your layout, outside error views (a 419 or 429 page can speak the browser's language).
`Seo::accountLanguageOffer()` returns the language on screen while the account holds another, and
`Seo::accountLanguage()` the account's:

```blade
@inject('seo', \Seo\Seo::class)
@if ($offer = $seo->accountLanguageOffer())
    <dialog id="account-language">
        <form method="POST" action="{{ route('seo.locale') }}">
            @csrf
            <input type="hidden" name="to" value="{{ request()->getRequestUri() }}">
            <p>{{ __('Use this language for your account too?') }}</p>
            <button name="locale" value="{{ $seo->accountLanguage() }}" class="keep">{{ __('No, keep mine') }}</button>
            <button name="locale" value="{{ $offer->code }}">{{ __('Yes') }}</button>
        </form>
    </dialog>
    <script type="module">
        const dialog = document.getElementById('account-language');
        // Escape closes it without a button, and Chrome may skip the cancel event: it counts as keeping.
        dialog.addEventListener('close', () => dialog.querySelector('form').requestSubmit(dialog.querySelector('.keep')));
        dialog.showModal();
    </script>
@endif
```

Both answers post to the switcher, so either leaves the page and the account in one language: Yes saves the page's
language to the account, No switches the page back to the account's. Escape counts as No; a click outside does nothing.

The package saves the one column with a normal model save, so model events fire. To save it your own way, such as
through a service that also clears a user cache, register a closure in a service provider's `boot(Seo $seo)`. The
package still reads `user_locale`, and sets it on the request's user:

```php
$seo->saveUserLocaleUsing(fn (User $user, string $code) => app(Users::class)->setLocale($user, $code));
```

It receives whichever guard's model is signed in when that model has the column, so type-hint accordingly.
Impersonation that signs you in as a member, such as lab404/laravel-impersonate's, writes the member's account through
the switcher and the one-time fill: skip the write in the closure while impersonating.

### Middleware order

`ResolveLocale` runs right after `StartSession`, so CSRF, throttle and `auth` refusals speak the visitor's language; it
never reads the user. `ApplyLocale` runs right after `AuthenticateSession`, and brings in the account and the entry
redirect. Neither answers a request or writes an account before your session check, as long as your priority list
ranks that check no later than `\Illuminate\Contracts\Session\Middleware\AuthenticatesSessions`.

- A list without the contract, like Laravel's `priority()` example, ranks `ApplyLocale` last, so the session check must
  be listed too. Laravel's `AuthenticateSession` ranks through the contract only when the list has it; otherwise, and
  for a subclass listed by its own name or a check without the contract like Sanctum's (Jetstream's extends it), list
  it yourself, no later:
  `$middleware->appendToPriorityList(\Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class, …)`.
- Refusals between the two (under Laravel's list, CSRF 419 and throttle 429) speak the language the visitor browses,
  else the browser's, not the account's. If your app ranks its session check earlier (e.g. right after `auth`), rank
  `ApplyLocale` after it too, never ahead of `auth`, so refusals after it speak the account's language:
  `$middleware->appendToPriorityList(after: AuthenticateSession::class, append: \Seo\Http\ApplyLocale::class);`

### Livewire and Inertia

- Livewire: add `\Seo\Http\ResolveLocale::class` and `\Seo\Http\ApplyLocale::class` to
  `Livewire::addPersistentMiddleware()`. Otherwise a component update on a copy renders in the visitor's language, not
  the page's. A component update never switches the language.
- An Inertia visit and `wire:navigate` are your app's own fetches: they open a copy like a page load.

### Your own locale logic

To choose the language yourself, set `'remember_locale' => false`. Only a copy's URL then sets the language: there is
no session, account, switcher route or entry redirect, and `seo:check` warns about a `user_locale` or `entry_redirect`
it ignores. `route()`, hreflang, the sitemap and `languages()` keep working. Set the language in your own middleware,
from the copy when the route is one:

```php
app()->setLocale(\Seo\LocalizedRoute::of($request->route())->locale ?? $code);
```

With Livewire, add this middleware to `Livewire::addPersistentMiddleware()`.

To 301 old query-parameter URLs (`/terms?lang=fr` to `/fr/terms`), redirect to
`\Seo\LocalizedRoute::of($request->route())?->path($request->getPathInfo(), $code)`. Check `$code` against your codes
first: `?lang=/evil.test` would otherwise redirect off-site.

## Configuration reference

Every key is optional, and a blank value counts as unset, except in `routes`, where it turns the routes off. A
malformed `url` or `disallow` entry throws where the site is built (see [Pages](#pages) for what production serves).

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
| `locales` | `[]` | Language codes, the first the default; fewer than two turns the language features off. See [Languages](#languages). |
| `remember_locale` | `true` | `false`: only a copy's URL sets the language. See [Your own locale logic](#your-own-locale-logic). |
| `user_locale` | `null` | The users' column holding each member's language. `null`: the session only. See [The account](#the-account-and-the-language-prompt). |
| `entry_redirect` | `[]` | Route names whose default copy sends a visitor arriving from outside to their language's copy. |

## Limits and non-goals

- llms.txt and Markdown page variants: Google Search ignores them.
- `changefreq`, `priority`, sitemap pings, `rel=next/prev` and meta keywords: Google and Bing ignore them.
- Snippet limits (`nosnippet`, `max-snippet`, `noarchive`): they only take visibility away.
- `og:locale` and `twitter:*` tags beyond `twitter:card`: no search effect, and X falls back to Open Graph.
- Bing's `msvalidate.01` (verify Bing by importing the site from Search Console) and subdirectory installs:
  `url` must be a bare origin.
- Typed schema.org helpers (pass `jsonLd` nodes), www and alias-host redirects, and a queued IndexNow job.
- Per-record alternates, such as a post in only some languages or a slug per language: every copy of a
  `Route::localized()` route is listed in hreflang and the sitemap, so keep such routes out of it.
- Language codes outside ISO 639-1 with an optional ISO 15924 script and ISO 3166-1 region, such as `es-419` or
  `fil`: Google ignores them in hreflang, so `locales` refuses them. Use `es` (or `es-MX`, `es-AR`) and `tl`. Each code
  is at once the hreflang value, the URL segment and the app locale, so name its translations after it (`lang/pt-BR/`,
  `lang/pt-BR.json`), not `pt_BR`.

## License

MIT. See [LICENSE](LICENSE).
