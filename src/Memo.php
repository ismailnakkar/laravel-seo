<?php

declare(strict_types=1);

namespace Seo;

use Illuminate\Http\Request;
use Throwable;
use WeakMap;

/** @internal Per-request state, bound scoped by the provider. */
final class Memo
{
    /**
     * The Site as last built, or its failure, under the locale it saw.
     *
     * @var WeakMap<Request, array{locale: string, site: Site|Throwable}>
     */
    public WeakMap $sites;

    /** @var WeakMap<Request, Page> */
    public WeakMap $pages;

    /** @var WeakMap<Request, array{nonce: string, title: ?string, description: ?string}> the pending head */
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

    /**
     * For the fail-open callers: the head, NoindexHosts and robots.txt each catch the same memoised site() failure,
     * which is one alert. rescue() because report() throws when logging fails.
     */
    public function report(Throwable $e): void
    {
        if (! isset($this->reported[$e])) {
            $this->reported[$e] = true;
            rescue(static fn () => report($e), report: false);
        }
    }
}
