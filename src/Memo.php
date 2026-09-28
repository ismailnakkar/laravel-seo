<?php

declare(strict_types=1);

namespace Seo;

use Illuminate\Http\Request;
use Throwable;
use WeakMap;

/** @internal Per-request state, bound scoped by the provider. */
final class Memo
{
    /** @var WeakMap<Request, array{locale: string, site: Site|Throwable}> */
    public WeakMap $sites;

    /** @var WeakMap<Request, Page> */
    public WeakMap $pages;

    /** @var WeakMap<Request, array<string, array{title: ?string, description: ?string}>> each head render's fallbacks, by its marker */
    public WeakMap $heads;

    /** @var WeakMap<Throwable, true> */
    private WeakMap $reported;

    public function __construct()
    {
        $this->sites = new WeakMap;
        $this->pages = new WeakMap;
        $this->heads = new WeakMap;
        $this->reported = new WeakMap;
    }

    /** Reports each failure once, though several fail-open callers catch it. rescue(): report() can throw. */
    public function report(Throwable $e): void
    {
        if (! isset($this->reported[$e])) {
            $this->reported[$e] = true;
            rescue(static fn () => report($e), report: false);
        }
    }
}
