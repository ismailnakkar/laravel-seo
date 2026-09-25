<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Seo\Http\SeoController;

// No middleware: no session, CSRF or cookies, and no throttle (crawlers read a 429 as a server error).
Route::get('robots.txt', [SeoController::class, 'robots'])->name('seo.robots');
Route::get('sitemap.xml', [SeoController::class, 'sitemap'])->name('seo.sitemap');
Route::get('sitemap-{n}.xml', [SeoController::class, 'sitemap'])->where('n', '[1-9][0-9]*')->name('seo.sitemap.chunk');
Route::get('indexnow-key.txt', [SeoController::class, 'indexNowKey'])->name('seo.indexnow');
