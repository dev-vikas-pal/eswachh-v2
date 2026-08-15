<?php

use App\Http\Controllers\Api\V1\Admin\AlertController;
use App\Http\Controllers\Api\V1\Admin\BackupController;
use App\Http\Controllers\Api\V1\Admin\ComplaintController;
use App\Http\Controllers\Api\V1\Admin\CustomerController;
use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\LogController;
use App\Http\Controllers\Api\V1\Admin\MasterController;
use App\Http\Controllers\Api\V1\Admin\PostController;
use App\Http\Controllers\Api\V1\Admin\ReminderController;
use App\Http\Controllers\Api\V1\Admin\ReportController;
use App\Http\Controllers\Api\V1\Admin\RoleController;
use App\Http\Controllers\Api\V1\Admin\RoundController;
use App\Http\Controllers\Api\V1\Admin\SiteSettingsController;
use App\Http\Controllers\Api\V1\Admin\SubscriptionActionController;
use App\Http\Controllers\Api\V1\Admin\SubscriptionBulkController;
use App\Http\Controllers\Api\V1\Admin\SubscriptionEditController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\Admin\VehicleController;
use App\Http\Controllers\Api\V1\Portal\PortalController;
use App\Http\Controllers\Api\V1\Shared\AccountController;
use App\Http\Controllers\Api\V1\Shared\AuthController;
use App\Http\Controllers\Api\V1\Shared\InvoiceController;
use App\Http\Controllers\Api\V1\Shared\MeController;
use App\Http\Controllers\Api\V1\Shared\PaymentController;
use App\Http\Controllers\Api\V1\Shared\PaymentDetailController;
use App\Http\Controllers\Api\V1\Shared\PhoneLoginController;
use App\Http\Controllers\Api\V1\Shared\PricingController;
use App\Http\Controllers\Api\V1\Shared\RenewalController;
use App\Http\Controllers\Api\V1\Shared\SettingsController;
use App\Http\Controllers\Api\V1\Shared\SubscriptionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Prefixed with /api/v1 in bootstrap/app.php. Versioned from the first commit
| because adding a version later means changing every caller.
|
| Authentication is the session cookie: the SPA is served from the same origin,
| so there is no token for a script on the page to read.
|
| Controllers are filed by who they are for, which is visible in the import
| list above and is the first thing to check when adding a route:
|
|   Admin\   the office - franchise owners and administrators
|   Portal\  the customer's own pages, where the session decides what is
|            returned and there is no id in the path to point elsewhere
|   Site\    no session at all: the marketing pages and the signup form
|   Shared\  genuinely used by more than one of the above - signing in, paying,
|            reading a plan. These are the ones that need an ownership check
|            inside them, because "who is asking" changes what the answer is.
|
| Filing a shared controller under Admin\ would be the dangerous mistake: it
| reads as though only staff reach it, and the row-level check then looks
| redundant to whoever edits it next.
|
*/

Route::post('login', [AuthController::class, 'login'])->name('login');

/*
 * Signing in with a code sent to a mobile, which is how v1's customers signed
 * in and how most of them still will: they were imported with hashes of
 * passwords they have never typed.
 *
 * Throttled at the route as well as inside the controller. The controller
 * limits per number and per address; this is the blunt ceiling underneath.
 */
Route::post('login/code', [PhoneLoginController::class, 'request'])->middleware('throttle:20,10');
Route::post('login/code/verify', [PhoneLoginController::class, 'verify'])->middleware('throttle:20,10');

/*
 * The gateway callback carries no session: Razorpay posts to it server to
 * server as well as through the browser. Its signature is its authentication,
 * checked before the body is used for anything. Throttled because it is the
 * only unauthenticated write in the API.
 */
Route::post('payments/callback', [PaymentController::class, 'callback'])
    ->middleware('throttle:60,1')
    ->name('payments.callback');


Route::middleware('auth:sanctum')->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);

    // Who am I, what may I do, which branches may I look at.
    Route::get('me', MeController::class);

    /*
     * Roles the business defines for itself, and the log viewer.
     *
     * Roles are super admin only, asked inside the controller rather than with
     * an ability: if managing roles were itself an ability it could be put on a
     * role, and that role could grant itself the rest.
     */
    Route::get('roles/catalogue', [RoleController::class, 'catalogue']);
    Route::get('roles', [RoleController::class, 'index']);
    Route::post('roles', [RoleController::class, 'store']);
    Route::patch('roles/{role}', [RoleController::class, 'update']);
    Route::delete('roles/{role}', [RoleController::class, 'destroy']);
    Route::post('users/{user}/role', [RoleController::class, 'assign']);

    // The application log, a day at a time. Administrator only: logs carry
    // phone numbers and payment references.
    Route::get('logs', [LogController::class, 'index']);
    Route::get('logs/{date}', [LogController::class, 'show']);

    // Your own password. No user id in the route: the account being changed is
    // the one signed in.
    Route::patch('me/password', [AccountController::class, 'changePassword']);

    /*
     * The customer's own pages.
     *
     * No id in any of these paths: what they return is decided by the session.
     * They are separate from the office's screens on purpose - reusing a list
     * endpoint "filtered to them" is one forgotten filter away from handing a
     * customer the whole branch.
     */
    Route::get('portal/overview', [PortalController::class, 'overview']);
    Route::get('portal/payments', [PortalController::class, 'payments']);
    Route::patch('portal/profile', [PortalController::class, 'updateProfile']);

    // Your own interface settings. Always yours: there is no user id here.
    Route::get('me/settings', [SettingsController::class, 'show']);
    Route::patch('me/settings', [SettingsController::class, 'update']);

    // Every dashboard tile in one response.
    Route::get('dashboard', DashboardController::class);

    Route::get('subscriptions', [SubscriptionController::class, 'index']);
    Route::get('subscriptions/{subscription}', [SubscriptionController::class, 'show']);

    /*
     * What the office does to a plan. Each is its own endpoint rather than a
     * general update, so the server decides what is allowed and each action
     * leaves a record.
     */
    Route::post('subscriptions/{subscription}/remind', [SubscriptionActionController::class, 'sendReminder']);
    Route::get('subscriptions/{subscription}/cleaners', [SubscriptionActionController::class, 'availableCleaners']);
    Route::post('subscriptions/{subscription}/cleaner', [SubscriptionActionController::class, 'assignCleaner']);
    Route::post('subscriptions/{subscription}/status', [SubscriptionActionController::class, 'setStatus']);

    /*
     * Doing one thing to many plans. Every action re-reads the ids through the
     * branch scope rather than trusting the list the browser ticked.
     */
    Route::get('subscriptions-bulk/templates', [SubscriptionBulkController::class, 'templates']);
    Route::post('subscriptions-bulk/cleaner', [SubscriptionBulkController::class, 'assignCleaner']);
    Route::post('subscriptions-bulk/message', [SubscriptionBulkController::class, 'sendMessage']);

    // Customers: their own screen, their own table. Not the staff list.
    Route::get('customers', [CustomerController::class, 'index']);
    Route::post('customers', [CustomerController::class, 'store']);
    Route::get('customers/{customer}', [CustomerController::class, 'show']);
    Route::patch('customers/{customer}', [CustomerController::class, 'update']);

    // A customer's cars. Nested, because nobody looks for a car - they look
    // for the person who owns it.
    Route::post('customers/{customer}/vehicles', [VehicleController::class, 'store']);
    Route::patch('customers/{customer}/vehicles/{vehicle}', [VehicleController::class, 'update']);
    Route::delete('customers/{customer}/vehicles/{vehicle}', [VehicleController::class, 'destroy']);
    Route::post('customers/{customer}/vehicles/{vehicle}/cleaner', [VehicleController::class, 'assignCleaner']);

    Route::get('payments', [PaymentController::class, 'index']);
    Route::get('payments/summary', [PaymentController::class, 'summary']);
    Route::post('payments/manual', [PaymentController::class, 'recordManually'])
        ->middleware('can:create.payment');
    Route::post('subscriptions/{subscription}/pay', [PaymentController::class, 'start']);
    // Development only: refused outright once a real gateway is configured.
    Route::post('payments/{payment}/simulate', [PaymentController::class, 'simulate']);

    // Renewing, and buying more cloths. Almost every payment after the first
    // is one of these.
    Route::post('subscriptions/{subscription}/renew', [RenewalController::class, 'renew']);
    Route::post('subscriptions/{subscription}/top-up', [RenewalController::class, 'topUp']);

    // Creating and editing plans. The amount always comes from the price book.
    Route::post('subscriptions', [SubscriptionEditController::class, 'store']);
    Route::patch('subscriptions/{subscription}', [SubscriptionEditController::class, 'update']);
    // Kept last: a literal segment above would otherwise be swallowed by {payment}.
    Route::get('payments/{payment}', [PaymentController::class, 'show']);
    Route::get('payments/{payment}/invoice', InvoiceController::class);
    // Everything about one payment: what it bought, what the gateway called it,
    // and what else has been paid on the same plan.
    Route::get('payments/{payment}/detail', PaymentDetailController::class);

    /*
     * Complaints.
     *
     * Every move is its own endpoint rather than a PATCH that sets a status,
     * so the server decides which transitions are legal and each one records
     * why it happened. A generic update would let a client jump a complaint
     * straight from open to closed with nothing written down.
     */
    // Before the {complaint} route, or the literal segment is swallowed.
    Route::get('complaints/options', [ComplaintController::class, 'options'])->middleware('can:view.complaint');
    Route::get('complaints', [ComplaintController::class, 'index'])->middleware('can:view.complaint');
    Route::post('complaints', [ComplaintController::class, 'store'])->middleware('can:create.complaint');
    Route::get('complaints/{complaint}', [ComplaintController::class, 'show'])->middleware('can:view.complaint');
    Route::post('complaints/{complaint}/assign', [ComplaintController::class, 'assign']);
    Route::post('complaints/{complaint}/notes', [ComplaintController::class, 'addNote']);
    Route::post('complaints/{complaint}/resolve', [ComplaintController::class, 'resolve']);
    Route::post('complaints/{complaint}/reopen', [ComplaintController::class, 'reopen']);
    Route::post('complaints/{complaint}/close', [ComplaintController::class, 'close']);

    // The round: what a cleaner is due to do, and what happened.
    Route::get('round', [RoundController::class, 'today'])->middleware('can:view.round');
    Route::post('round/vehicles/{vehicle}', [RoundController::class, 'recordService']);
    Route::post('round/vehicles/{vehicle}/cloth', [RoundController::class, 'recordCloth']);
    Route::get('cloth-movements', [RoundController::class, 'clothLedger']);
    Route::post('attendance', [RoundController::class, 'markAttendance']);
    Route::get('attendance/coverage', [RoundController::class, 'coverage']);

    // Pricing for the office. Same engine as the public quote.
    Route::post('pricing/quote', [PricingController::class, 'quote']);
    Route::get('subscriptions/{subscription}/renewal-quote', [PricingController::class, 'renewal']);

    /*
     * The masters: geography and the price list.
     *
     * Shared across every franchise, so only a super admin may edit them - the
     * ability check lives on the controller rather than being repeated here.
     * Deleting is a withdrawal from sale, never a real delete, because live
     * plans point at these rows.
     */
    /*
     * Staff and customer accounts.
     *
     * The escalation rules - who may create whom, and who may move somebody
     * between branches - live in the controller rather than here, because they
     * depend on the acting user's own role and cannot be expressed as a route
     * middleware.
     */
    Route::get('users', [UserController::class, 'index']);
    Route::post('users', [UserController::class, 'store']);
    Route::patch('users/{user}', [UserController::class, 'update']);
    Route::delete('users/{user}', [UserController::class, 'destroy']);
    Route::post('users/{id}/restore', [UserController::class, 'restore']);

    // Reports. Branch scoped by the models, with no parameter to widen it.
    // What has been said to customers. 'Did we tell them?' is asked on every
    // chasing call, and a log file is not an answer.
    Route::get('reminders', [ReminderController::class, 'index']);

    Route::get('reports', [ReportController::class, 'index']);
    Route::get('reports/revenue', [ReportController::class, 'revenue']);
    Route::get('reports/renewals', [ReportController::class, 'renewals']);
    Route::get('reports/service', [ReportController::class, 'service']);
    Route::get('reports/complaints', [ReportController::class, 'complaints']);
    Route::get('reports/cloth', [ReportController::class, 'cloth']);

    /*
     * The blog. Publishing is its own endpoint rather than a field on update,
     * so an article cannot go live as a side effect of fixing a typo.
     */
    Route::get('posts', [PostController::class, 'index']);
    Route::post('posts', [PostController::class, 'store']);
    Route::get('posts/comments', [PostController::class, 'comments']);
    Route::patch('posts/comments/{comment}', [PostController::class, 'moderate']);
    Route::get('posts/{post}', [PostController::class, 'show']);
    Route::patch('posts/{post}', [PostController::class, 'update']);
    Route::delete('posts/{post}', [PostController::class, 'destroy']);
    Route::post('posts/{post}/publish', [PostController::class, 'publish']);

    // Things needing attention. Read is per person, resolved is for everybody.
    Route::get('alerts', [AlertController::class, 'index']);
    Route::post('alerts/read-all', [AlertController::class, 'markAllRead']);
    Route::post('alerts/{id}/read', [AlertController::class, 'markRead']);
    Route::post('alerts/{id}/resolve', [AlertController::class, 'resolve']);

    /*
     * Backups. Never on a public disk: a dump holds every customer detail and
     * every hashed password, so it is served through a checked route.
     */
    Route::get('backups', [BackupController::class, 'index']);
    Route::post('backups', [BackupController::class, 'store']);
    Route::get('backups/{name}', [BackupController::class, 'download']);
    Route::delete('backups/{name}', [BackupController::class, 'destroy']);

    // Business details: one set for the whole business, so administrator only.
    Route::get('site-settings', [SiteSettingsController::class, 'show']);
    Route::patch('site-settings', [SiteSettingsController::class, 'update']);

    Route::get('masters', [MasterController::class, 'catalogue']);
    Route::get('masters/{master}', [MasterController::class, 'index']);
    Route::post('masters/{master}', [MasterController::class, 'store']);
    Route::patch('masters/{master}/{id}', [MasterController::class, 'update']);
    Route::delete('masters/{master}/{id}', [MasterController::class, 'destroy']);
    Route::post('masters/{master}/{id}/restore', [MasterController::class, 'restore']);
});
