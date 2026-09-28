<?php

declare(strict_types=1);

// Blank values count as unset, except in routes.
return [
    // Title suffix, og:site_name, and the home page's Organization and WebSite name. null: app.name.
    'name' => null,

    // The origin every canonical, sitemap and share URL is built on, e.g. https://example.com. null: app.url.
    'url' => null,

    'title_separator' => ' · ',

    // false: a page with no @seo or Seo::page() call renders noindex, follow, so adding the head to an existing site
    // indexes only the pages you describe.
    'index_by_default' => true,

    // Share image, unless the page sets one: an absolute URL or a path on url. PNG, JPEG or WebP, ideally 1200×630.
    // null: no og:image, no twitter:card.
    'image' => null,

    // Organization logo on the home page: URL or path, at least 112×112.
    'logo' => null,

    // The home page's schema.org Organization subtype, e.g. OnlineBusiness, LocalBusiness.
    'organization_type' => 'Organization',

    'alternate_names' => [],

    // Official profile URLs.
    'same_as' => [],

    'google_verification' => env('SEO_GOOGLE_VERIFICATION'),

    // robots.txt path prefixes blocked on every host. End them in '/': '/admin' also blocks '/administrators'.
    'disallow' => [],

    // Hosts or URLs served noindex, nofollow, e.g. a download or short-link domain.
    'noindex_hosts' => [],

    // Route names, or paths ('/about') and URLs. A Seo::sitemapUsing() entry for the same loc replaces one here.
    'sitemap' => [],

    // 8 to 128 letters, digits or dashes; served at /indexnow-key.txt.
    'index_now_key' => env('INDEXNOW_KEY'),

    // Serve /robots.txt, /sitemap.xml, /sitemap-{n}.xml and /indexnow-key.txt. false, null or '': your own routes serve them.
    'routes' => true,
];
