<?php

declare(strict_types=1);

namespace Seo;

enum HostRole: string
{
    /** The Site::$url host: rules plus the Sitemap line. */
    case index = 'index';

    /** Any other host the app answers on (short, link, alias, www): rules, no Sitemap line. */
    case crawl = 'crawl';

    /** Crawlable, but noindex, nofollow in the head, and in X-Robots-Tag. */
    case noindex = 'noindex';
}
