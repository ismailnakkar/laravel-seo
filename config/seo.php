<?php

declare(strict_types=1);

// Blank values count as unset, except in routes.
return [
    // null: app.name.
    'name' => null,

    // The origin every URL is built on, never the request host. null: app.url.
    'url' => null,

    'title_separator' => ' · ',

    // false: pages with no @seo or Seo::page() render noindex, follow, so only described pages get indexed.
    'index_by_default' => true,

    // Default share image: URL or path, PNG, JPEG or WebP, ideally 1200×630. null: no og:image, no twitter:card.
    'image' => null,

    // Home page Organization logo: URL or path, at least 112×112.
    'logo' => null,

    // A schema.org Organization subtype, e.g. OnlineBusiness.
    'organization_type' => 'Organization',

    'alternate_names' => [],

    'same_as' => [],

    'google_verification' => env('SEO_GOOGLE_VERIFICATION'),

    // robots.txt prefixes blocked on every host. End them in '/': '/admin' also blocks '/administrators'.
    'disallow' => [],

    // Hosts or URLs served noindex, nofollow, e.g. a short-link domain.
    'noindex_hosts' => [],

    // Route names, paths ('/about') or URLs. A sitemapUsing() entry for the same loc replaces one here.
    'sitemap' => [],

    // 8 to 128 letters, digits or dashes; served at /indexnow-key.txt.
    'index_now_key' => env('INDEXNOW_KEY'),

    // false, null or '': your own routes serve robots.txt, the sitemaps and indexnow-key.txt.
    'routes' => true,
];
