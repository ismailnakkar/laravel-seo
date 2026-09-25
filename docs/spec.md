# ismailnakkar/laravel-seo: design spec

A general Laravel SEO package. An app describes its site in `config/seo.php`, renders `<x-seo::head />`, and
describes each page in its view: `@section('title')` and `@section('description')`, and `@seo(...)` for the rest. A
controller calls `$seo->page(...)` only for values the view does not have or should not carry. The routes, the
noindex-host middleware, the head fill and the maintenance exemption for robots.txt wire themselves. Two optional
closures cover what a config file cannot hold. The package needs no app-side bindings and declares no interfaces.

Design rules:

- **Config first.** Every key has a default; an empty config renders a valid head from `app.name` and `app.url`.
- **Pages are indexable by default.** With `index_by_default => false`, a page is noindex until it calls `page()`
  or `@seo`.
- **Pages are described where their content is.** The view carries the title, description and `@seo`; order in
  the views does not matter, because the head is filled once the response exists (§4.3).
- **Per-page values merge.** A controller's `page()` and a view's `@seo` each replace the fields they pass; the
  later call wins, a blank argument never erases, and `jsonLd` appends.
- **No output without an effect.** Every emitted tag, header and robots line has a documented consumer: a search
  engine, an AI agent or a link preview.
- **One URL builder.** Canonical, hreflang, `og:url`, sitemap locs, the robots `Sitemap:` line and the IndexNow key
  location all come from `Site`, never from the request host.

[README.md](../README.md) is the user documentation; this spec records the design behind it and does not repeat
its tables.

Four problems live outside the package. It makes each one visible instead of fixing it:

| Problem | Who fixes it | How the package surfaces it |
|---|---|---|
| Cross-domain auth bounces that loop cookieless clients | App code | `seo:check --link`, `followRedirectChain()` |
| nginx `location = /robots.txt` without `try_files`, or an edge rewriting robots.txt | Server and edge config | `seo:check` byte-diffs the live robots.txt against the app's body |
| www hosts that 404, http without an https redirect | Edge redirect rules, or a global middleware in the app | `seo:check` `www` and `http` rows |
| An alias catch-all shadowing paths on alternate hosts | The app, which owns route order | Not in scope |

---

## 1. Goals and non-goals

### Goals

1. **One head renderer**: title, description, robots, canonical, hreflang, Open Graph, `twitter:card`, the Google
   verification meta and JSON-LD. Server-rendered by a Blade component; one `Site` build per request; no file I/O.
2. **One URL builder** (above). The root URL is always `{origin}/`.
3. **robots.txt per host role**: one `User-agent: *` group, no agent ever named, and a `Sitemap:` line on the
   index host only. It always answers 200: on a failed build it reports and serves a permissive body marked
   `no-store`.
4. **Sitemaps**, built on request: one `<urlset>` up to 50,000 URLs, a `<sitemapindex>` of 50,000-URL files past
   that. Locs on the index host only, `lastmod` only when real, locale variants expanded from `Route::localized()`
   markers.
5. **IndexNow**: a fixed key-file route (404 until a key is set) and `seo:indexnow` for the URLs that changed.
6. **Host policy**: `NoindexHosts` on the global stack adds `X-Robots-Tag: noindex, nofollow` on noindex hosts and
   fails open. www and alias-host canonicalisation stays at the edge or in the app's own middleware.
7. **Test helpers** that fetch as a cookieless Googlebot, for crawlability, robots, static shadows, sitemap,
   hreflang and noindex-vs-disallow.
8. **`seo:check`**: a live audit of what a kernel test cannot see: robots substitution, edge AI blocks, www and
   http redirects, `X-Robots-Tag` on noindex hosts, cookieless auth loops and home-page brand signals.

### AI search and agents

Google: "You don't need to create new machine readable files, AI text files, markup, or Markdown". The AI work is
crawl hygiene:

1. No agent is named in robots.txt, so AI search and assistant agents inherit `User-agent: *`. A named group
   would replace `*` (RFC 9309 §2.2.1) and drop the global disallows.
2. The API cannot emit snippet limits: `nosnippet` and `max-snippet` also limit AI Overviews and AI Mode, and
   Bing's `noarchive`/`nocache` limit Copilot answers.
3. Every indexable URL answers 200 to a cookieless client within 3 hops (`assertCrawlable`, `seo:check --link`).
4. Edge blocks on AI search agents are probed live (`seo:check` `crawlers`).

### Non-goals

| Item | Reason |
|---|---|
| llms.txt, llms-full.txt, `.md` page variants | Google: "will neither harm nor help". No AI search vendor documents reading it. No package route claims the path, so a static file still works. |
| `max-snippet`, `nosnippet`, `noarchive`, `nocache`, `unavailable_after`, `noimageindex` | They only take visibility away (see above). |
| Canonical-host middleware, or a package fallback route | An app has one `Route::fallback()` and a package must not claim it. The edge does it in one hop; `seo:check` verifies either. |
| A `Disallow: /` block-all role | A disallowed URL can still be indexed from links, and its noindex is never read. `HostRole::noindex` replaces it. |
| Named groups for search engines or AI search agents | A named group replaces `*`. Applebot and Brave also fall back to a Googlebot group. |
| Rules for AI-training crawlers | robots.txt stays one `*` group, so training crawlers read the same rules as everyone. An app that blocks them does it at the edge by user agent, never by rewriting robots.txt, which fails `seo:check`'s byte diff. |
| `Content-Signal`, AIPREF `Content-Usage`, Markdown for Agents, Web Bot Auth, WebMCP, NLWeb, agent cards | No vendor honours them, or drafts with no search consumer. |
| `Clean-param`, `Crawl-delay`, `Host`, Yandex verification | Google ignores the directives. |
| `changefreq`, `priority`, sitemap ping, `rel=next/prev`, meta keywords | Google and Bing ignore them; the ping endpoint is gone. |
| FAQPage, SearchAction, AggregateRating, BreadcrumbList helpers | FAQ rich results ended 2026-05-07, SearchAction 2024-11-21, and self-serving ratings are ineligible. Raw `jsonLd` passes through. |
| `twitter:title/description/image/site`, `og:locale:alternate`, sitemap `xhtml:link` | X falls back to OG; hreflang uses one method, HTML links. |
| `og:type` other than `website` | No caller needs one. |
| Favicons, analytics, ad tags | The app layout's. |
| A queued IndexNow job | No per-change trigger in any app; an app can call the command from its own job. |
| Query-parameter locales, redirects by language, translated slugs | Google marks `?lang=` not recommended and advises against language redirects; every copy keeps the default's URIs. |
| Search Console and edge dashboard settings | Not code. |

---

## 2. Package conventions

- The PHP 8.4 floor brings `Dom\HTMLDocument`, an HTML5 parser that closes `<head>` where Google does.
- **Namespace** `Seo\`, tests `Seo\Tests\`. `SeoServiceProvider` is `@internal`.
- **Class shapes**: `declare(strict_types=1)` everywhere. DTOs are `final readonly` with promoted properties and
  validate only code-sourced values, throwing `InvalidArgumentException` naming the field. Enums are string-backed
  with camelCase cases. `Seo` is neither final nor readonly, because apps mock it. Controllers, middleware,
  commands and the component are `final`. Constants are typed.

---

## 3. Configuration and the Site build

`config/seo.php` is the package's only config file; README "Configuration" documents each key. Blank values count as
unset everywhere except `routes`, where any falsy value turns the routes off.

**The build.** `Seo::site()` builds `Site` from the overrides over `config('seo')`: a null override falls through
to config, a blank one counts as unset.

- `name` falls back to `app.name`, `url` to `app.url`. List keys drop blank items.
- `index_by_default` becomes `Site::$indexByDefault`, so an override can change it.
- `sitemap` and `routes` are read from config directly, never from overrides.
- Config and closures resolve on `Container::getInstance()`, never a container captured when `Seo` was built:
  under Octane that is the request's sandbox.

**Overrides in code**, both optional:

- `siteUsing(Closure $resolver)`: the closure returns `seo.*` keys that override config for the Site. It exists
  for values an admin edits. It runs whenever a Site is built, which is every request once it is set
  (`NoindexHosts` builds one), so it must be cheap, and in the console (`seo:check`, `seo:indexnow`), so it must
  never read the Request. Setting it resets the memo.
- `sitemapUsing(Closure $resolver)`: the closure returns an iterable of `SitemapEntry`, one per page (§4.5).
- Both run through `Container::getInstance()->call()`, so parameters are injected. `Seo` arrives by method
  injection in a provider's `boot()`.

**Per-request state.** `Seo` is a singleton holding only the two closures. The provider binds `Seo\Memo`
(`@internal`) scoped. It holds three `WeakMap`s keyed by Request: `sites` (`array{locale, site}`), `pages` (the merged
`Page`) and `heads` (`array{nonce, title, description}`: the pending head's marker and fallbacks).

- A scoped binding is forgotten between Octane requests and between queue jobs, whose console Request is shared,
  so nothing leaks from one to the next.
- `site($request)` is memoised per Request and app locale: a `siteUsing()` closure may read the locale, and
  `SetLocale` runs after the first build. `site()` without a Request rebuilds on every call.
- Nothing builds a Site at boot; the provider reads only `seo.routes`.

**Validation follows the source.** Only code-sourced values throw: `seo.url`, a `seo.disallow` entry, a
`seo.sitemap` value, `Page::$canonical`, `Page::$jsonLd` and `Locales`. Admin-edited
values (name, image, verification code, logo, IndexNow key) are never validated at render time, so one bad
settings row cannot 500 every page; the test helpers and `seo:check` catch them, and apps validate them where they
are written.

---

## 4. Public API

### 4.1 `Seo`

`@seo(<expression>)` compiles to `<?php app(\Seo\Seo::class)->page(<expression>); ?>`, so it takes what `page()`
takes. A literal `@seo` in view text is written `@@seo`.

**Order does not matter.** The head is built when the response exists (§4.3), from the final Page, so `page()` or
`@seo` anywhere in the views counts: below the head, in a partial, in a view holding `<head>` itself, in a nested
full render. Every rendered view's `@seo` merges into the one Page, so a partial rendered twice appends its `jsonLd`
twice. A call after the fill, such as in middleware after `$next()`, has no effect. The head's own view renders at the
fill, so a published copy gets only its variables (no sections, stacks or component attributes). Left with the comment:
HTML rendered to a string and served later (a string cache's hits, a mail, a PDF, `artisan down --render`), a test's
`view()`/`blade()`, and an error page thrown inside a route as route middleware sees it.

### 4.2 URL rules

**`Site::to($path)`**: a value with a leading scheme is returned unchanged; `//host/x` takes `$url`'s scheme;
anything else is `$url . '/' . ltrim($path, '/')`, so `''` and `'/'` give `https://host/`. A request URI must
never reach it: `//evil.test/x` would name that host.

**`Site::canonical($request, $page)`**:
1. `$page->canonical` set: `to($page->canonical)`.
2. Otherwise `'/' . trim($request->getPathInfo(), '/')` on `$url`: `/index.php` and a trailing slash dropped, the
   request host ignored. A localized copy's path is respelt as the router decoded it (`/%66r/faq` → `/fr/faq`).
3. `?page=N` is kept only when `$page->paginated` and `FILTER_VALIDATE_INT` with `min_range 2` accepts it, the
   test Laravel's paginator applies: `+2` becomes `2`, `02` gives the bare URL. Every other parameter, and any
   array input, is dropped.

**`Site::alternates($request, $page)`**: `[]` outside `Route::localized()` or with a canonical override;
otherwise every code's path with the same page number, in codes order, then `x-default` (the default's href).

### 4.3 `<x-seo::head />`

`Seo\View\Head(Htmlable|string|null $title = null, Htmlable|string|null $description = null)`, registered with
`componentNamespace('Seo\\View', 'seo')`. Blade hands an enclosing component's undeclared attributes to a nested
component's constructor, so it takes only the two fallbacks and resolves its services in `render()`. Props (string
or slot) and sections are HTML, decoded once before the view escapes them: `title="{{ $t }}"` and
`@section('title', $t)` arrive escaped.

**Deferred.** `render()` captures the fallbacks (the props, else the sections as they stand when it renders) in the
memo's `heads` and prints `<!--seo-head:{nonce}-->`, 16 random hex digits per request, so page content cannot forge it.
The provider listens to `ResponsePrepared`, which the router fires once it has made the action's return value a
Response, before route middleware (a response cache) reads the body, and to `RequestHandled` for the exception handler's
pages, which the router never prepares. Both call `Head::fill()`: with nothing pending it returns at once; on an
`Illuminate\Http\Response` (never a JSON, streamed or file response) holding the marker it builds the head from the
final Page, the fallbacks and the Site, replaces the first marker, removes the others (a nested full render), restores
the response's `original` (the View `assertViewHas()` reads) and clears the pending state. A marker rendered outside a
response (a mail, HTML cached with `view()->render()`) stays a comment.

**Error responses.** A status of 400 and up ignores the Page, which was set for content the response does not serve
(a controller that calls `page()`, then `abort(404)`). The head takes the error view's fallbacks and the site image,
has no `og:url` or JSON-LD, and renders noindex. The error view's own `@seo` is ignored too, since it merges into the
same Page: an error view titles itself with `@section('title')`.

With `$site = $seo->site($request)`, and `$page = $seo->pageFor($request)`, or null on an error response:

| Output | Rule |
|---|---|
| robots | `Robots::none` on a noindex host; else `Robots::noindex` on an error response; else `$page->robots`; else `index_by_default ? Robots::index : Robots::noindex` |
| `<title>`, `og:title` | the first non-blank of `$page->title`, the prop, the section, trimmed; plus `titleSeparator . name` unless `suffixSiteName` is false. None: `name` |
| description, `og:description` | the first non-blank of `$page->description`, the prop, the section; none: omitted |
| canonical, hreflang | indexable robots only |
| `og:url` | the canonical; on a noindex page `to($page->canonical)` when set; else omitted |
| `og:image`, `og:image:alt`, `twitter:card` | `$page->image ?? $site->image`, through `to()`. Alt: `name` for the site image, the page's unsuffixed title for a page image |
| `google-site-verification`, home `@graph` | only when the canonical is the home URL (`isHome`: the root, so `/fr` gets neither) |
| page scripts | one per `$page->jsonLd` node, after the graph |

**Emission order** (void tags without `/>`): title, description, robots, canonical, alternates; `og:site_name`,
`og:type` (`website`), `og:title`, `og:description`, `og:url`; `og:image`, `og:image:alt`, `twitter:card`;
`google-site-verification`; the graph script, then the page scripts.

**Home `@graph`**: the Organization node (`@type` = `organization_type`, `name`, `alternateName` when set, `url`
`{origin}/`, `logo` via `to()` when set, `sameAs` when set), then WebSite (`name`, `alternateName` when set, `url`).

**JSON-LD encoding**: `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP |
JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR`. `</script>` cannot close the
tag, invalid UTF-8 in an admin-edited value becomes U+FFFD instead of a 500, and depth or NAN still throw.

**Placement**: first in `<head>`, right after charset and viewport. Google ends `<head>` at the first element that
does not belong there, and a canonical after it is never read.

### 4.4 Routes and controller

```php
Route::get('robots.txt', [SeoController::class, 'robots'])->name('seo.robots');
Route::get('sitemap.xml', [SeoController::class, 'sitemap'])->name('seo.sitemap');
Route::get('sitemap-{n}.xml', [SeoController::class, 'sitemap'])->where('n', '[1-9][0-9]*')->name('seo.sitemap.chunk');
Route::get('indexnow-key.txt', [SeoController::class, 'indexNowKey'])->name('seo.indexnow');
```

- Loaded by the provider when `seo.routes` is true, **before** the app's routes, with **no middleware**: no
  session, CSRF, cookies or throttle (crawlers read a 429 as a server error). Global middleware still runs.
- An app route with the same method and URI and no domain replaces the package's. One inside a `Route::domain()`
  group matches before the package's on its host on Laravel 13, whose `RouteCollection` puts domain routes first;
  on Laravel 12 the package's matches first. On Laravel 13 a domain catch-all that matches a dot therefore answers
  that host's `/robots.txt`.
- **Maintenance**: the provider always calls `PreventRequestsDuringMaintenance::except(['robots.txt'])`, even with
  `routes` false: RFC 9309 reads a 503 robots.txt as disallow-all.
- **Cache headers**, success only: `setPrivate()->setMaxAge(3600)->setEtag(xxh128 of the body)`, then
  `isNotModified()` for a 304. Google reads only those two; `private` keeps a shared cache from serving a stale
  file. Route-level `cache.headers` would also cache the fail-open body.

`SeoController`:

- **`robots`**: `$site->robotsTxt($site->roleOf($host), $seo->sitemapUrl($site))`, `text/plain; charset=UTF-8`.
  On any `Throwable` it reports inside `rescue(…, report: false)` (a log channel that cannot write must not turn
  the fallback into a 500) and serves `"User-agent: *\nDisallow:\n"` with `Cache-Control: no-store`: a 5xx
  robots.txt makes Google stop crawling for 12 hours.
- **`sitemap(?string $n)`**: another host gets a 301 to the same file on `Site::$url`. Otherwise
  `$seo->sitemapFile($name)`, `Content-Type: application/xml` exactly; null is a 404. It does not fail open: a
  broken entry or resolver is a normal 500.
- **`indexNowKey`**: the key as `text/plain` when it matches `/^[A-Za-z0-9-]{8,128}$/D`, else 404.

### 4.5 Sitemap

**`Seo::sitemap()`**, lazy:
1. Resolve the config values: a string starting with `/` or containing `://` is a loc as given; any other string
   must be a route name (`route($name, absolute: $route->getDomain() !== null)`, so a domain route on another host
   fails the host check); anything else throws `InvalidArgumentException("seo.sitemap: [x] is not a route name.
   Write paths with a leading '/', e.g. '/x'.")`.
2. Expand each config loc and key it by its first (default-locale) URL.
3. Yield the resolver's entries, expanded, removing the config set they share a first URL with, so a resolver
   `/fr/faq` replaces a config `/faq`.
4. Yield the remaining config sets, without lastmod.

Memory holds the config list only. **Expansion** of one loc: `Site::to()`; the host must be `Site::host()`, else
`LogicException` ("robots.txt advertises the sitemap on the index host only"); the path and query are rebuilt with
Guzzle's `Uri`, which percent-encodes as RFC 3986 asks and keeps valid escapes (`/über` → `/%C3%BCber`); the query
is never parsed (`parse_str()` renames `v1.2` and folds repeated keys); a trailing slash is dropped as in the
canonical. Then, only when the app has `Route::localized()` routes, the loc is matched (never bound:
`RouteCollection::match()` would overwrite the current route's parameters) against the GET routes carrying the
marker, fallbacks last; a match on any copy expands to every code's URL, default first, lastmod copied.

**Files.** `sitemapFiles()` makes one pass holding one file: up to 50,000 URLs it yields `sitemap.xml` as a
`<urlset>`; past that it yields `sitemap-1.xml`… of 50,000 each, then `sitemap.xml` as their `<sitemapindex>`,
last. Locs are `htmlspecialchars(ENT_XML1 | ENT_QUOTES)`; lastmod is `DATE_ATOM`.

**`sitemapUrl($site)`** is the one place that decides whether a sitemap exists: a `seo.sitemap` value or a
resolver. Null means `/sitemap.xml` is 404 and robots.txt has no `Sitemap:` line; the
controller, `SeoAssertions` and `seo:check` all ask it.

### 4.6 `NoindexHosts`

Global middleware, pushed by the provider onto the HTTP kernel (`pushMiddleware()` skips a class already present).
Global, not group-scoped: a noindex host's `/` may be answered outside any group. It appends
`X-Robots-Tag: noindex, nofollow` on `HostRole::noindex` hosts, never replacing an existing header (engines apply
the most restrictive). It fails open: a throwing Site build is reported and the response passes unchanged. A
maintenance 503 lacks the header, since Laravel's check runs first; static files served by nginx lack it too.

### 4.7 Languages

**Decisions.** Subdirectories with the default bare: `/terms` English, `/fr/terms` French, no English URL moves.
Static prefixes, not a `{locale}` parameter: no injected controller argument, no `URL::defaults`, and router-derived
reserved-alias lists pick the codes up. The marker is a plain route-action key (survives group merging and
`route:cache` on Laravel 12). hreflang uses HTML links only. No language redirect anywhere. `<html lang>` and
`dir` stay in the app layout.

**`Locales(array $codes, string $default)`**: each code is at once the hreflang value, the URL segment and the app
locale, shaped `/^[a-z]{2}(-[A-Z][a-z]{3})?(-[A-Z]{2})?$/D`. Empty or duplicate codes, a malformed code, or a
default outside the codes throw. The default comes from code, never `config('app.locale')`, which
`setLocale()` overwrites.

**`Route::localized(Locales $locales, Closure $routes)`** runs the closure once per code: every other code's copy
first under a static `{code}` prefix named `seo.{code}.` (after an enclosing `Route::name()` prefix:
`pages.seo.fr.terms`), then the default's copy bare, each carrying the
`seo_locale` action marker and `SetLocale`. The default registers last because the first match wins, and a
default route opening with a parameter (`{page}`, a fallback) would otherwise catch `/fr/…`. It throws inside a
prefix group or another `Route::localized()`, and on a route-level prefix, which lands before the locale; only the
finished URIs show that, so the check runs over them.

**`LocalizedRoute::path($path, $code)`** maps this copy's path to another code's (`/fr/terms`, `ar` →
`/ar/terms`; `/fr`, `en` → `/`). The router matches the decoded path, so the prefix is stripped as it decodes
(`/%66r%2Fterms` → `/fr/terms`). A path without the prefix throws: a caller bug, never a request. `$code` is not
checked against the codes, so a caller passing a request value (an app's 301 from a legacy `?lang=`) checks it first.

**Locale resolution.**
- A `RouteMatched` listener sets the app locale from the copy as soon as the route matches, before
  `SubstituteBindings`, so a translated slug binds in the URL's language.
- `SetLocale` (route middleware, gathered after `web`) sets it again, overriding an app locale middleware in `web`.
  It never reads or writes Accept-Language, cookies, the session, the IP or the user.

**URL formatter.** The provider wraps `UrlGenerator::formatPathUsing()`: a path on any copy of a localized route
is mapped to the current locale's (`Container::getInstance()->getLocale()`, so a notification's `withLocale()`
applies); a locale outside the codes gets the default's. `route()`, `to_route()`, `action()` and signed routes
follow; `url()`, Ziggy and hard-coded paths do not. Mapping every copy keeps `action()` stable whichever copy the
route collection kept. A formatter set before the package still runs after it.

### 4.8 Console

**`seo:install`**: exits 1 before changing anything when `config/seo.php` has a `model` key or a non-scalar `image`
(ralphjsmit/laravel-seo's shape: both read the `seo` key). Runs `vendor:publish --tag=seo-config` (never
overwrites). Deletes `public/robots.txt`, `public/sitemap.xml` and `public/indexnow-key.txt`: Laravel's stock
robots.txt unasked, any other after a confirmation that defaults to no. A failed delete exits 1. Never at runtime:
the deletion is a repository change the owner commits.

**`seo:check {url?*} {--link=*} {--sample=5}`**: read-only; every fetch is `withoutRedirecting()`, `timeout(10)`,
no cookies. URLs default to `Site::$url`, grouped by host; each host's role picks its rows. It probes `https://H`,
`https://www.H` and `http://H`, so it checks a deployed site, never `artisan serve`. Rows print as
`  {check padded with dots to 16} {PASS|FAIL|WARN|SKIP} {detail}`; exit 1 on any FAIL.

| Row | Role | Rule |
|---|---|---|
| `robots.txt` | every | GET `https://H/robots.txt` (Chrome UA): 200, `text/plain`, byte-identical to `Site::robotsTxt($role, sitemapUrl)`. A diff FAILs with the first differing line, quoted with control and non-ASCII octets escaped. |
| `sitemap` | index | SKIP without a sitemap. `/sitemap.xml`: 200, `application/xml` or `text/xml`, a `<urlset>` or a `<sitemapindex>` whose every file is a `<urlset>`; every entry on H and allowed for Googlebot by the app's body. The first bad file FAILs. |
| `sample`, `descriptions` | index | `--sample` locs from the urlset or the index's first file (the first, then evenly spaced), as Googlebot smartphone: 200, one canonical equal to the loc, at most one `<title>` in `<head>`, no noindex meta or header (read as Googlebot reads it), no SVG `og:image`. A page without a description WARNs. |
| `home` | index | A WebSite JSON-LD node named `Site::$name` (FAIL); a set logo answering 200 as PNG, JPEG, WebP, GIF, AVIF, BMP or SVG and allowed for Googlebot (FAIL); a title without the name WARNs; a name still Laravel's default `Laravel` WARNs, checked before the fetch so it shows while the page is down. |
| `crawlers` | index | OAI-SearchBot, Claude-SearchBot, PerplexityBot, DuckAssistBot, Amzn-SearchBot, meta-webindexer, MistralAI-Index and Claude-User, UA `Mozilla/5.0 (compatible; {token}/1.0)`, each get a 2xx; a fetch error or non-2xx FAILs. SKIP when the Chrome baseline is refused. Search engines are verified by IP, so they are not probed. |
| `http` | every | `http://H/` answers one 301 or 308 to `https://H/` or `Site::to('/')`. |
| `www` | hosts not starting `www.` | `https://www.H/`, and on the index host its first non-root sitemap path, answer one 301 or 308 to the same path on H or on `Site::$url`. SKIP when www does not resolve or connect. |
| `x-robots-tag` | noindex | `/` must say noindex (FAIL); `/favicon.ico` 2xx without it WARNs, since nginx serves static files itself. |
| `cookieless` | each `--link` | Up to 10 redirects as Googlebot without cookies. A loop, an unparseable `Location` or a final non-200 FAILs; more than 3 hops WARNs. |

A `Location` is resolved as a browser resolves it. The rows judge by the app's body, which equals the live one once
the robots.txt row passes. It judges against the local Site, so it runs wherever that resolves to production's
values (process env over `.env`, config uncached), never on the production box.

**`seo:indexnow {url?*} {--all}`**: URLs or paths, each through `Site::to()` first, so a path lands on `Site::$url`;
or `--all` (every `Seo::sitemap()` loc plus any given, deduplicated; meant for a migration or redesign only). Exits 1
and sends nothing when there are no URLs, the key is missing or malformed, the `seo.indexnow` route is not registered,
a URL is off `Site::host()`, or `--all` finds none. Chunks of 10,000 are posted to `https://api.indexnow.org/IndexNow`
as `{host, key, keyLocation, urlList}`; 200 and 202 print PASS, anything else or a connection error prints FAIL and
exits 1 while later chunks still go.

### 4.9 Testing helpers

`Seo\Testing\SeoAssertions` (trait). Every fetch is one kernel GET: the session is flushed and guards forgotten
first (the kernel shares both, so a login on one hop would hide a loop on the next), and the test's default
headers, cookies, server variables and `followingRedirects()` neither leak in nor change.

- A fetch that must answer 200 (`assertCrawlable`, `robotsTxt`, `assertHreflangReciprocal`, each sitemap file) asserts
  the status through Laravel's `TestResponseAssert`, as `assertStatus()` does, so the failure message also carries
  the exception behind it.
- `followRedirectChain`: fails with the whole chain on a revisited URL or past `$maxHops`; an unparseable Location
  fails naming it.
- `assertCrawlable`: the final response is 200, not noindex, and after an HTML5 parse has exactly one `<title>` and
  one canonical in `<head>` (equal to the final URL), no title, canonical, robots meta or hreflang in `<body>`, and
  no SVG `og:image`.
- `robotsTxt`: `http://{host}/robots.txt` answers 200 `text/plain` and equals `Site::robotsTxt()` for the host's role.
- `assertSitemapComplete`: fails when `sitemapUrl()` is null. Follows an index into each file,
  which must be a `<urlset>`. Every loc is on the host, unique, allowed for Googlebot and `assertCrawlable($loc, 0)`;
  titles, and non-empty descriptions, are unique per `<html lang>`; the home page's same-host stylesheets, scripts,
  `og:image` and logo are allowed for Googlebot. Structural only: never content an admin edits.
- `assertHreflangReciprocal`: fetches each alternate with an `Accept-Language` naming a different language; each
  answers 200, is self-canonical, emits the same set and renders an `<html lang>` of its hreflang's language. The
  default is the code whose href equals x-default's; none fails.

`Seo\Testing\RobotsMatcher(string $body)` reads a robots.txt body: groups split on blank lines, Disallow rules only
(`Site::robotsTxt()` writes the one `*` group; named groups come from a foreign body). `allows(string $token, string
$path)` reads the token's group, else `*`; `*` in a rule is any sequence and only a trailing `$` anchors; non-ASCII
octets and hex escapes compare in one spelling; `/robots.txt` is always allowed; a token that is not a product token
(a full user-agent string would silently read `*`) or a path not starting with `/` throws.

For a moved URL, apps use Laravel's `assertMovedPermanently()->assertRedirect($to)`; its `assertPermanentRedirect()`
asserts a 308.

### 4.10 `Seo\ParsedPage` (`@internal`)

Shared by the trait and `seo:check`, so both judge a page, header or URL the same way. `parse()` uses
`Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR)`: a `<div>` or `<img>` in `<head>` closes it, as Google
reads it. It exposes `htmlLang`, `titles`, `title`, `canonicals`, `seoTagsInBody`, `robots` (meta `robots` and
`googlebot`), `description`, `alternates`, `ogImage`, `jsonLd`, `stylesheets`, `scripts`, `svgOgImage()`, and the
statics `headerRobots()` (each X-Robots-Tag line on its own: a `crawler:` prefix scopes the rest of that line, and
valued directives are never read as a crawler), `sitemap()` (root and locs, or null), `sameUrl()`, `resolve()`,
`robotsPath()`, `mediaType()`, with the `GOOGLEBOT` (smartphone), `CHROME` and `SITEMAP_TYPES` constants.

---

## 5. Package test plan

`vendor/bin/phpunit` on PHP 8.4+; `composer check` also runs `pint --test` and Larastan at level 7 over `src` and
`tests` (`composer analyse`). `tests/TestCase.php` registers the provider, flushes the static maintenance exemptions
before each test, prevents stray HTTP, and sets `app.url` to `http://localhost`, the index host. `withSite()` sets
`seo.*` (name `UpFiles`, image `/img/og-image.png`, disallow `/admin/`, noindex host `dl.test`); every other host such
as `go.test` crawls. The fixture pages (`/`, `/faq`, `/payment-proof`, `/reset-password`) run in Testbench's `web`
group; the package routes run in no group. `visit($url, ?Page, ?title)` renders a page whose controller spreads the
Page into `page()`; `withLocales()` registers the same pages in `Route::localized()`; `withSitemap()` sets a resolver.

Exact outputs are pinned by HeadTest, RobotsTxtTest, SitemapTest, IndexNowTest and CheckCommandTest.

Behaviour changes are test-first: a new or changed test fails before the change and passes after.

---

## 6. Risks

- **Formatter slot.** `formatPathUsing()` has one slot. A formatter set after the package boots silently turns
  localized links off.
- **Links that bypass `route()`** (`url()`, Ziggy, hard-coded paths) link to the default locale.
- **Middleware order.** An app locale middleware ranked ahead of `SubstituteBindings` overrides the URL's locale for
  bindings unless it skips localized routes. A redirect to a localized route built on an unlocalized route before
  the app's locale middleware runs (an `auth` bounce, `AuthenticateSession`'s logout) goes to the default copy.
- **Twin names.** Copy names appear in Ziggy's payload and fail `routeIs('x')`; use `seo.*.x`.
- **Alias shadowing.** The `/{code}` home copies shadow a live alias of the same name on a host that serves aliases.
- **Kernel tests.** The app locale outlives a `/fr` request in the test process; build URLs before fetching.
- **Indexable by default.** A tokened URL on an undescribed page reaches the canonical; such pages pass
  `Robots::noindex`, or the app sets `index_by_default => false`.
- **Head outside a response.** HTML rendered to a string and served on a later request (a string cache), a mail or
  a PDF keeps the marker comment and no head. A response cache in route middleware is fine.
- **Late sections.** Sections are read when the head renders: one the layout defines below `<x-seo::head />` is
  missed. `@seo` is not.
