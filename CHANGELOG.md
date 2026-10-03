# Changelog

All notable changes to `ismailnakkar/laravel-seo` are listed here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

While 0.x, a breaking change bumps the minor and a patch never breaks. Semver covers what the README documents;
anything marked `@internal` may change in any release.

## [0.5.2] - 2026-10-03

### Fixed

- UPGRADE.md installs `laravel-localization:^0.3`, not `^0.1`.

### Docs

- The sitemap section says responses are not cached and how to cache a costly resolver.

## [0.5.1] - 2026-09-28

### Changed

- The `Seo::alternatesUsing()` closure runs once per page, not twice, and still never for a noindex or error page or
  one that sets its own canonical.

### Fixed

- An `alternatesUsing()` answer whose `alternates` have integer keys, such as a list of paths, throws a
  `LogicException`. 0.5.0 rendered `hreflang="0"`.
- A `sitemapUsing()` entry that is not a `SitemapEntry` throws a `LogicException` naming `Seo::sitemapUsing()`, not a
  PHP error.
- `seo:check` on the apex of a `www.` site no longer FAILs the `www` row, which expected the site itself to redirect. A
  host given in Unicode (`bücher.test`) is checked as its punycode, so the site's own host is no longer taken for
  another.
- A page that renders another full view keeps its own head's title and description fallbacks. The nested view's
  replaced them.

## [0.5.0] - 2026-09-28

Every step from 0.4 is in [UPGRADE.md](UPGRADE.md#from-04-to-05).

### Changed

- **Breaking:** localized routes and the visitor's and account's language moved to a new package,
  [ismailnakkar/laravel-localization](https://github.com/ismailnakkar/laravel-localization), with the same behaviour.
  laravel-seo is SEO only. The renames:
  - `Seo\Locales`, `Seo\LocalizedRoute`, `Seo\Language` and `Seo\UserLocale` are `Localization\Locales`,
    `Localization\LocalizedRoute`, `Localization\Language` and `Localization\UserLocale`.
  - `Seo\Http\ResolveLocale`, `ApplyLocale`, `SwitchLocale` and `RedirectToDefaultCopy` are in `Localization\Http\`.
  - `Seo::languages()`, `accountLanguage()`, `accountLanguageOffer()` and `saveUserLocaleUsing()` are on
    `Localization\Localization`, same names.
  - `seo.locales`, `seo.remember_locale`, `seo.user_locale` and `seo.entry_redirect` are `localization.*` in
    `config/localization.php`, same defaults, published with `--tag=localization-config`.
  - The switcher route `seo.locale` is `localization.switch`, still `POST /locale`.
  - A copy's route name `seo.{code}.{name}` is `localization.{code}.{name}`.
  - The route action keys `seo_locale` and `seo_default_redirect` are `localization_locale` and
    `localization_default_redirect`.
  - The session key `seo.browsing` is `localization.browsing`, so the language each session browses is forgotten once.
  - `seo:check`'s `entry_redirect` and `user_locale` rows are `php artisan localization:check`.

### Added

- `Seo::alternatesUsing()`: a closure that gives, for a route and a path, the page's canonical path and each language's
  path, for hreflang and sitemap expansion. laravel-localization registers it; any other localization setup can. See
  the README's [Multilingual sites](README.md#multilingual-sites).

### Removed

- The `jaybizzle/crawler-detect` dependency.

## [0.4.2] - 2026-09-27

### Added

- `Seo::accountLanguage()`: the signed-in user's saved language, for the prompt's answer that keeps it.

### Changed

- The README's account-language prompt asks Yes or No, keep mine, and Escape counts as No, so the page and the account
  always end up in one language. Its Not now left them apart until the tab closed.

## [0.4.1] - 2026-09-27

### Added

- `/en/terms`, with `en` the default language, answers a 301 to `/terms`, keeping the query string, and counts as
  opening the English copy, so an `entry_redirect` page no longer sends the visitor back to another language. Only
  `Route::localized()` GET pages redirect: any other `/en/…` path still 404s. A route of your own on `/en/…` wins
  only when registered before `Route::localized()`.

## [0.4.0] - 2026-09-27

Every step from 0.3 is in [UPGRADE.md](UPGRADE.md#from-03-to-04).

### Changed

- **Breaking:** a page without a language in its URL renders the language the visitor browses in this session, then
  the account's, then the browser's, then the default. In 0.3 the account came first.
- **Breaking:** opening a `Route::localized()` copy in another language switches the session only. The account's
  language changes only through the switcher; an account without one is filled once, on a page view, with the language
  the visitor gets. Signing in on a copy no longer replaces it, so mail, other devices and other hosts keep the
  account's language.
- **Breaking:** the session key is `seo.browsing`, so the languages 0.3 sessions hold are forgotten once.
- **Breaking:** `seo.locales` in the `code => name` form throws. List the codes.
- **Breaking:** under Laravel's default middleware priority, a CSRF (419) or throttle (429) refusal for a member who
  has not browsed another language this session speaks the browser's language, not the account's.
- From another origin, a sibling subdomain included, only a top-level GET opens a copy: a sibling's fetch or image no
  longer switches the language.
- A guard whose model lacks the `user_locale` column (an admin's) costs no query per page.
- Marked `@internal`: the controllers, the command classes (the commands are the API), `Seo\View\Head` (the tag is the
  API), `HostRole`, `Locales`, `Seo::pageFor()`, `Seo::sitemapUrl()`, `LocalizedRoute::name()`, `unprefixedUri()`, its
  constructor and `$locales`, and `Site::roleOf()`, `canonical()`, `alternates()`, `robotsTxt()` and `isHome()`.

### Added

- `remember_locale` (default `true`). `false` leaves only the URL setting the language: no session, account, switcher
  route or entry redirect. `seo:check` warns when `user_locale` or `entry_redirect` is set while it is off.
- `Seo::accountLanguageOffer()`, for a "use this language for your account too?" prompt. The README has the snippet.
- This changelog, and [UPGRADE.md](UPGRADE.md) for the upgrade notes that were in the README.

### Fixed

- A site that cannot be built (a throwing `siteUsing()`, an invalid `url` or `disallow`) no longer turns every page,
  error pages included, into a 500 in production. The error is reported once per request and the page gets only its
  `<title>` and `noindex, nofollow`, uncached. Other environments still throw.

0.3.3 and earlier: see [UPGRADE.md](UPGRADE.md).

[0.5.1]: https://github.com/ismailnakkar/laravel-seo/compare/v0.5.0...v0.5.1
[0.5.0]: https://github.com/ismailnakkar/laravel-seo/compare/v0.4.2...v0.5.0
[0.4.2]: https://github.com/ismailnakkar/laravel-seo/compare/v0.4.1...v0.4.2
[0.4.1]: https://github.com/ismailnakkar/laravel-seo/compare/v0.4.0...v0.4.1
[0.4.0]: https://github.com/ismailnakkar/laravel-seo/compare/v0.3.3...v0.4.0
