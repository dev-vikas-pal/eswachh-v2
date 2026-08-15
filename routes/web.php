<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Two applications, one origin
|--------------------------------------------------------------------------
|
| The public website and the working application are separate bundles, so a
| visitor reading the price list never downloads the reports screen or the user
| management form.
|
| They share an origin deliberately: the session cookie that authenticates the
| application is same-origin, and splitting them across hosts would mean
| tokens in JavaScript instead.
|
*/

/*
 * The application: the office under /app, the customer's own pages under /my,
 * and the sign in page both arrive at.
 *
 * /my has to be listed here and excluded below. Without it the customer portal
 * fell through to the public bundle, whose catch-all sends anything it does not
 * recognise to the home page - so every reload of a portal page, and every link
 * into it from the site, quietly bounced the customer back to the marketing
 * pages as though they had been signed out.
 */
Route::view('/login', 'app')->name('login.page');
Route::view('/app/{any?}', 'app')->where('any', '.*')->name('app');
Route::view('/my/{any?}', 'app')->where('any', '.*')->name('portal');

// The public website: everything else that is not the API or a framework route.
Route::view('/{any?}', 'site')
    ->where('any', '^(?!api|sanctum|up|storage|login|app|my).*$')
    ->name('site');
