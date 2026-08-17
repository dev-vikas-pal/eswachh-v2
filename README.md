# Eswachh

Doorstep car cleaning, sold as a subscription. One system with three doors into
it: a public site, an office, and a customer's own pages.

This is v2 — a rebuild of the previous Laravel 10 / nwidart-modules system,
carrying its data and its logins across.

| | |
|---|---|
| **Back end** | Laravel 12 · PHP 8.2+ · MySQL |
| **Front end** | Vue 3.5 · TypeScript 5.9 · Vite 7 · Pinia · TanStack Query · Tailwind 4 |
| **Auth** | Sanctum, SPA cookie |
| **Payments** | Razorpay |
| **Messaging** | WhatsApp via MSG91 |
| **Tests** | 395 passing, 1102 assertions, ~65s |

## Getting it running

```bash
composer setup     # composer, .env, key, migrate, npm, build
php artisan db:seed
composer dev       # server + queue + log tail + vite, all four
```

Two things in `.env` are not optional:

- **`APP_TIMEZONE=Asia/Kolkata`** — v1 ran on it. On UTC every imported
  timestamp is out by five and a half hours and date filters silently find
  nothing.
- **`DB_CONNECTION=mysql`** — the importer reads a v1 database directly. The
  working database is `u841499718_eswachh_testing_v2`; the suite has its own
  (`..._phpunit`) because it drops every table.

`php artisan eswachh:check-integrations` will tell you whether payments and
messaging are wired up and, when they are not, what is stopping them.

## The three doors

| URL | Who | Bundle |
|---|---|---|
| `/` | Anybody | `resources/js/site` |
| `/app` | Administrators, franchise owners, cleaners | `resources/js/admin` |
| `/my` | Customers | `resources/js/portal`, shipped inside the admin bundle |

Signing in at `/login` sends a person to whichever of the last two is theirs.
The role decides; nobody chooses.

## Documentation

| | |
|---|---|
| [`docs/admin-guide.pdf`](docs/admin-guide.pdf) | What the system does, screen by screen — for owners, administrators, and explaining it to a client. Includes what is built but deliberately switched off. |
| [`docs/developer-guide.pdf`](docs/developer-guide.pdf) | Where every flag lives, what to edit for a given change, the conventions, and the bugs this codebase has already had. Read section 13 before anything else. |
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | The short version of how the code is filed. |

Both PDFs are generated from the HTML beside them. Edit the HTML and re-render:

```bash
chrome --headless --disable-gpu --no-pdf-header-footer \
       --print-to-pdf=docs/admin-guide.pdf docs/admin-guide.html
```

## Who sees what

A **sector is the territory**. Staff are assigned sectors through `user_sector`;
a customer sits in one sector; a record is visible when those two meet.

```
users ──< user_sector >── sectors ──< customers ──< subscriptions
```

There is no franchise entity above this, and nothing is copied onto the customer,
so handing a sector to somebody else is one pivot row and takes effect on their
next request. Territory is assigned on the **People** screen.

The one exception is money: `payments.sector_id` is stamped at capture and never
recomputed, so revenue already collected stays with whoever collected it.

Fail closed is the rule that matters — covering nothing returns nothing, never
everything. `tests/Feature/SectorScopeTest.php` pins it.

## Two rules that are not negotiable

**No amount ever comes from a request.** Every price is worked out from the
masters by `Domain\Pricing\PriceBook`, on the server, at the moment it is
charged. The figure the browser shows is a quote and is thrown away. v1 read
`final_price` straight from the form.

**Nothing is marked paid except a verified callback.** Signup, renewal, top-up
and add-a-car all open a payment and stop. `Domain\Billing\RecordPayment` moves
it, and only after the Razorpay signature checks out and the gateway confirms
what it holds.

## Messages never leave a non-production copy

`Messenger::deliveryEnabled()` requires production *and* `WHATSAPP_ENABLED`, and
returns false during tests whatever the configuration says. Everywhere else a
message is written to the `messages` table exactly as it would have been sent,
with the reason it was held back. A demonstration against real data cannot
message a real customer.

## Tests

```bash
php artisan test                            # all of them
php artisan test --filter=AddSecondCarTest  # one file
npx vue-tsc --noEmit                        # the front end
```

`tests/Concerns/MigratesOnlyWhenStale` skips `migrate:fresh` when the test
database already matches every migration on disk — 217s down to about 47.
Adding a migration rebuilds it automatically.

Use `vue-tsc` rather than `npm run build` while working; `npm run dev` is
normally already running and a build fights it.

## Working on this repository

- **Don't commit.** The owner commits manually.
- Finish the whole task, then run `php artisan test` once — not after each step.
- Don't edit PHP through shell heredocs or node scripts. Passing PHP through a
  shell strips `$` sigils and `\` namespace separators, silently producing
  `AppModelsPayment`. It has caused real bugs here.
