<?php

use App\Http\Controllers\ReceiptController;
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
/*
 * A receipt, opened from the message we sent when the money was taken.
 *
 * Signed rather than authenticated: most customers never make an account, and a
 * receipt only reachable by signing in is one they will never see. The
 * signature is checked by the middleware, so a doctored id is refused before
 * the controller runs.
 */
Route::get('/receipt/{payment}', ReceiptController::class)
    ->middleware('signed')
    ->name('receipt');

/*
 * The Renew now button in a WhatsApp message lands here.
 *
 * Short on purpose, and a path rather than a query string, because that is all
 * a WhatsApp button can be: Meta approves a fixed address ending in `{{1}}` and
 * appends what is sent to the end of it. A signed link like the receipt above
 * puts its signature in the query string and could not be used.
 *
 * Named, because the address is generated in three places that must agree - the
 * button on the approved template, the link sent with each message, and this
 * page - and all three ask the router for it rather than writing it out.
 *
 * It serves the public bundle, which the catch-all below would do anyway; what
 * this adds is the name. The Vue router has a matching `/r/:token` route, and
 * without that its own catch-all would send every one of these to the home page.
 */
Route::view('/r/{token}', 'site')->name('renew.link');

Route::view('/login', 'app')->name('login.page');
Route::view('/app/{any?}', 'app')->where('any', '.*')->name('app');
Route::view('/my/{any?}', 'app')->where('any', '.*')->name('portal');

// The public website: everything else that is not the API or a framework route.
// `receipt` joins the exclusions: without it the catch-all would swallow the
// signed link and show the marketing site instead of the document.
Route::view('/{any?}', 'site')
    ->where('any', '^(?!api|sanctum|up|storage|login|app|my|receipt).*$')
    ->name('site');
