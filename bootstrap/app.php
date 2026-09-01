<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            /*
             * The public API is a separate file from the application's.
             *
             * Not tidiness: it makes the boundary reviewable. Everything in
             * api_public.php is reachable by anyone on the internet, and
             * everything in api.php is behind a session - so adding a route to
             * the wrong file is a visible mistake in a diff rather than one
             * line lost in three hundred.
             */
            Route::middleware('api')
                ->prefix('api/v1')
                ->group(base_path('routes/api_public.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * Cookie based authentication for the Vue front end.
         *
         * The SPA is served from the same origin, so it authenticates with the
         * session cookie and a CSRF token rather than a bearer token kept in
         * JavaScript. Nothing sensitive is readable by a script on the page.
         */
        $middleware->statefulApi();

        // Every API response is JSON, including auth failures. Without this a
        // 401 redirects to a login route that does not exist here.
        $middleware->redirectGuestsTo(fn () => null);

        /*
         * Whole features the business has switched off - `feature:blog`.
         *
         * Registered as an alias so it reads as part of the route definition,
         * which is where somebody adding an endpoint to a gated group will
         * actually look.
         */
        $middleware->alias([
            'feature' => \App\Http\Middleware\RequiresFeature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * Never leak an exception message to an API client. The previous
         * system returned raw SQL to customers on a failed payment; this makes
         * that shape of mistake impossible to repeat by accident.
         */
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
