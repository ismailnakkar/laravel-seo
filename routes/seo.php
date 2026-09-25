<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Seo\Http\SeoController;

// No middleware: no session, CSRF or cookies, and no throttle (crawlers read a 429 as a server error).
Route::controller(SeoController::class)->name('seo.')->group(static function (): void {
    Route::get('robots.txt', 'robots')->name('robots');
    Route::get('sitemap.xml', 'sitemap')->name('sitemap');
    Route::get('sitemap-{n}.xml', 'sitemap')->where('n', '[1-9][0-9]*')->name('sitemap.chunk');
    Route::get('indexnow-key.txt', 'indexNowKey')->name('indexnow');
});
