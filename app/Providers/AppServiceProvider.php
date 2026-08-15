<?php

namespace App\Providers;

use App\Support\Database\SchemaMacros;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        SchemaMacros::register();

        /*
         * Fail loudly in development rather than quietly in production:
         * accessing an unloaded relation throws instead of firing a query per
         * row, which is how the previous system ended up issuing 200 queries
         * to render one page.
         */
        Model::preventLazyLoading(! app()->isProduction());
        Model::preventSilentlyDiscardingAttributes(! app()->isProduction());

        /*
         * Abilities come from the role enum. A super admin is allowed
         * everything here rather than by listing every ability on the role.
         */
        Gate::before(function ($user, string $ability) {
            return $user->seesAllBranches() ? true : null;
        });

        Gate::after(function ($user, string $ability) {
            return $user->hasAbility($ability);
        });
    }
}
