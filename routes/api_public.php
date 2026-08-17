<?php

use App\Http\Controllers\Api\V1\Shared\PricingController;
use App\Http\Controllers\Api\V1\Shared\RenewalController;
use App\Http\Controllers\Api\V1\Site\BlogController;
use App\Http\Controllers\Api\V1\Site\CatalogueController;
use App\Http\Controllers\Api\V1\Site\ClothTopUpController;
use App\Http\Controllers\Api\V1\Site\SignupController;
use Illuminate\Support\Facades\Route;



/*
|--------------------------------------------------------------------------
| The public site
|--------------------------------------------------------------------------
|
| No session at all. These serve the marketing pages and the signup form, so
| they return only what is on sale - names and prices - and never counts,
| customers or anything that describes the business.
|
| Throttled, because an unauthenticated endpoint that runs queries is the
| cheapest thing in the system to hammer.
*/
Route::middleware('throttle:120,1')->prefix('public')->group(function () {
    Route::get('catalogue', CatalogueController::class);
    // Banners and questions, kept apart from the price list: they change on a
    // different rhythm and the home page should not refetch prices to show a
    // headline.
    Route::get('content', [CatalogueController::class, 'content']);
    Route::get('locations', [CatalogueController::class, 'locations']);

    // Privacy, terms and refunds. Kept off the content endpoint so the home
    // page does not download twenty thousand characters to show a headline.
    Route::get('policy/{page}', [CatalogueController::class, 'policy']);

    // The blog and the team page. Only published articles are ever reachable:
    // the query starts from the published scope rather than filtering after.
    Route::get('posts', [BlogController::class, 'index']);
    Route::get('posts/{slug}', [BlogController::class, 'show']);
    Route::get('team', [BlogController::class, 'team']);

    // Anyone may leave a comment; nothing appears until it is approved.
    // Throttled harder than the read endpoints, because a comment box is the
    // one thing on the public site that writes to the database.
    Route::post('posts/{slug}/comments', [BlogController::class, 'comment'])
        ->middleware('throttle:5,1');


    // The price comes from here and nowhere else. Nothing accepts an amount
    // from a request body, which is what let v1 be charged ₹1 for any plan.
    Route::post('quote', [PricingController::class, 'quote']);

    /*
     * Renewing without an account, by quoting a car number.
     *
     * The only unauthenticated route that can find a customer record, so it is
     * deliberately narrow - a first name and a price, nothing else - and
     * throttled hard enough that the plates of a city cannot be walked through.
     */
    /*
     * Signing up.
     *
     * The only unauthenticated route that creates records, so the number is
     * proved with a code first and the price is the server's, worked out from
     * the masters that were chosen.
     *
     * The two halves are throttled differently on purpose. Asking for a code
     * sends a message and costs money, so it stays tight - and it is limited
     * per number and per address inside the controller as well. Submitting the
     * form sends nothing and creates nothing until every check has passed, and
     * a customer correcting a car number, an address and a typo can easily need
     * five or six attempts. At ten per five minutes they were being locked out
     * of their own signup for getting something wrong, which reads as the site
     * being broken.
     */
    Route::post('signup/code', [SignupController::class, 'requestCode'])->middleware('throttle:10,5');
    Route::post('signup', [SignupController::class, 'store'])->middleware('throttle:40,5');

    // Topping up cloths by quoting a car number, as v1 had it.
    Route::post('cloth/lookup', [ClothTopUpController::class, 'lookup'])->middleware('throttle:10,5');
    Route::post('cloth/pay', [ClothTopUpController::class, 'pay'])->middleware('throttle:40,5');

    Route::post('renew/lookup', [RenewalController::class, 'lookup'])->middleware('throttle:10,5');
    Route::post('renew/pay', [RenewalController::class, 'payWithoutSigningIn'])->middleware('throttle:40,5');
});
