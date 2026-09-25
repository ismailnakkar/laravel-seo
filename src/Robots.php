<?php

declare(strict_types=1);

namespace Seo;

enum Robots: string
{
    /** Indexable. Large previews for Discover and AI answers; snippet limits deliberately absent. */
    case index = 'max-image-preview:large';

    /** Out of the index, links followed: first-party utility pages (password reset, verify). */
    case noindex = 'noindex, follow';

    /** Out of the index, links not followed: pages pointing at user-submitted destinations (link interstitials). */
    case none = 'noindex, nofollow';

    public function indexable(): bool
    {
        return $this === self::index;
    }
}
