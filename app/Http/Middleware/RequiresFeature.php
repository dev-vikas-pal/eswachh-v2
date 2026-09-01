<?php

namespace App\Http\Middleware;

use App\Support\Settings\Features;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shuts a whole feature's endpoints when the business has switched it off.
 *
 * Applied to the route rather than checked inside each controller, because the
 * failure mode of the second approach is silent: one endpoint out of six
 * forgets the check, nothing looks wrong, and the feature is still answering
 * for anybody who kept a link. A middleware on the group covers what the group
 * covers, and a route added to that group inherits the gate.
 *
 * A 404 rather than a 403. "Switched off" and "does not exist" are the same
 * thing seen from outside, and a 403 would confirm to a crawler that there is
 * something here worth coming back for.
 */
class RequiresFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        abort_unless(Features::on($feature), 404);

        return $next($request);
    }
}
