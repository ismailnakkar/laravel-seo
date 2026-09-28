<?php

declare(strict_types=1);

namespace Seo;

enum Robots: string
{
    /** Indexable, with large image previews for Discover and AI answers. */
    case index = 'max-image-preview:large';

    /** Utility pages, such as password reset. */
    case noindex = 'noindex, follow';

    /** Pages linking to user-submitted URLs. */
    case none = 'noindex, nofollow';

    public function indexable(): bool
    {
        return $this === self::index;
    }
}
