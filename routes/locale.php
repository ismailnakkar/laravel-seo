<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Seo\Http\SwitchLocale;

// `web` for the session and CSRF. POST: it changes state (Django's set_language), and crawlers never submit it.
Route::post('locale', SwitchLocale::class)->middleware('web')->name('seo.locale');
