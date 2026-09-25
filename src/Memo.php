<?php

declare(strict_types=1);

namespace Seo;

use Illuminate\Http\Request;
use WeakMap;

/** @internal Per-request state, bound scoped by the provider. */
final class Memo
{
    /** @var WeakMap<Request, array{locale: string, site: Site}> the Site as last built, under the locale it saw */
    public WeakMap $sites;

    /** @var WeakMap<Request, Page> */
    public WeakMap $pages;

    /** @var WeakMap<Request, array{nonce: string, title: ?string, description: ?string}> the pending head */
    public WeakMap $heads;

    public function __construct()
    {
        $this->sites = new WeakMap;
        $this->pages = new WeakMap;
        $this->heads = new WeakMap;
    }
}
