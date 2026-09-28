<?php

declare(strict_types=1);

namespace Seo;

/** @internal */
enum HostRole
{
    /** The Site::$url host. */
    case index;

    /** Any other host the app answers on. */
    case crawl;

    /** A seo.noindex_hosts host: crawlable, but noindex, nofollow. */
    case noindex;
}
