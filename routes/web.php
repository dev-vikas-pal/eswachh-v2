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

// The application. Everything under /app, plus the sign in page.
Route::view('/login', 'app')->name('login.page');
Route::view('/app/{any?}', 'app')->where('any', '.*')->name('app');

// The public website: everything else that is not the API or a framework route.
Route::view('/{any?}', 'site')
    ->where('any', '^(?!api|sanctum|up|storage|login|app).*$')
    ->name('site');
