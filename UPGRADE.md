# Upgrade guide

## From 0.3 to 0.4

Require `^0.4`, deploy, then rebuild the caches: `php artisan optimize`. No config change is needed unless you use the
`code => name` form of `locales`. Where the 0.3.x notes below differ, the rules here replace them.

**The language rules** (multilingual apps). See the README's
[How the language is chosen](README.md#how-the-language-is-chosen).

- The session and the account are now two things. The session holds the language the visitor browses: opening a
  `Route::localized()` copy in another language sets it, for every page of the site in that browser. The account holds
  the member's saved language, for mail, other devices and other hosts.
- A page without a language in its URL renders the session's language, then the account's, then the browser's, then
  the default. In 0.3 the account came first.
- Opening a copy never changes a saved language, signing in on one included: a member `ar` who opens `/fr` sees a
  French dashboard, and the account stays `ar`. Only the switcher does. An account without a language is filled once,
  on a page view, with the visitor's, and a language your own sign-up stores is never overwritten.
- To let a member save the language on screen to their account, add the prompt from the README's
  [The account and the language prompt](README.md#the-account-and-the-language-prompt).
  `Seo::accountLanguageOffer()` returns that language while the account holds another.
- A guest who uses the switcher just before signing in keeps that language for the session, and an account that already
  holds one keeps it.
- Under Laravel's default middleware priority, a CSRF (419) or throttle (429) refusal for a member who has not browsed
  another language this session now speaks the browser's language, not the account's. An app that ranks its session
  check earlier ranks `ApplyLocale` after it with one line: see the README's
  [Middleware order](README.md#middleware-order).
- From another origin, a sibling subdomain included, only a top-level GET opens a copy.
- Tests that expect a page view to replace `user_locale` now fail: assert the page's language, or post the switcher.

**The session key is reset.** It is now `seo.browsing`, so every language a 0.3 session holds is forgotten once:
members fall back to their account, guests to their browser.

**The keyed `locales` form is removed.** `'locales' => ['en' => 'English', 'fr' => 'Français']` throws. List the codes,
default first: `['en', 'fr']`.

**`remember_locale` is new** (default `true`). If your app sets the locale itself, set it to `false` and guard your
middleware, as in the README's [Your own locale logic](README.md#your-own-locale-logic).

**A broken SEO config no longer takes pages down in production.** A throwing `siteUsing()`, or an invalid `url` or
`disallow`, is reported, and pages are served with only a `<title>` and `noindex, nofollow`. Run `seo:check` in your
deploy, and alert on reported errors: uptime checks see 200.

**Newly `@internal`:** the controllers, the command classes, `Seo\View\Head`, `HostRole`, `Locales`, `Seo::pageFor()`,
`Seo::sitemapUrl()`, `LocalizedRoute::name()`, `unprefixedUri()`, its constructor and `$locales`, and `Site::roleOf()`,
`canonical()`, `alternates()`, `robotsTxt()` and `isHome()`. Code calling them keeps working for now, but may break in
any release.

## Upgrading from 0.3.2

- Opening a `Route::localized()` page now saves its language as the visitor's choice: to the session, and to the
  account's `user_locale` when it differs, signing in on the page included unless it is the copy in the browser's
  language (the default when none matches). Before, a page only set a new session's choice (its first `/fr/…` page,
  however it came) and filled an account without a language; replacing a choice took the switcher. A signed link, an
  image or frame, or another site's form post now sets no choice, not even a new session's.
- A click on a link to the default copy, such as a `url()` or hard-coded path, or a 404 page's logo, now saves the
  default language. Link with `route()`.

## Upgrading from 0.3.1

- `seo.locales` is a list of codes, default first: `['en', 'fr', 'es']`. `code => name` still reads; the names are
  ignored.
- `$language->name` is gone: label the switcher with your own text, each in its own language, such as
  `{{ __("languages.{$language->code}", locale: $language->code) }}`.

## Upgrading from 0.3.0

- Delete any priority line of your own that ranks `ResolveLocale`: the package's ranking yields to it, and one after
  `auth` sends a guest it turns away to `/login`, not `/fr/login`.
- Livewire: add `\Seo\Http\ApplyLocale::class` to `Livewire::addPersistentMiddleware()` next to `ResolveLocale`.
- `withoutMiddleware(ResolveLocale::class)` no longer skips the entry redirect or the account fills: they moved to
  `ApplyLocale`, so name both.

## Upgrading from 0.2

- Move the codes into `seo.locales`, default first, and drop the `Locales` argument:
  `Route::localized(function () { … })`.
- `Seo\Http\SetLocale` is gone. Remove any locale middleware of your own: it would override a copy's language.
- A localized copy answers to the route's own name: `terms` on `/fr/terms`, no longer `seo.fr.terms`.
- New dependency: `jaybizzle/crawler-detect`.
- Set `seo.locales` first: with fewer than two, `Route::localized()` registers plain routes, so every `/fr/…` URL
  would 404.
