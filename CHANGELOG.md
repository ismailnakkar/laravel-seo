# Changelog

All notable changes to `ismailnakkar/laravel-seo` are listed here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

While 0.x, a breaking change bumps the minor and a patch never breaks. Semver covers what the README documents;
anything marked `@internal` may change in any release.

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

[0.4.0]: https://github.com/ismailnakkar/laravel-seo/compare/v0.3.3...HEAD
