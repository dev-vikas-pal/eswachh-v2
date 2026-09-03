# Deploying v2 to Hostinger

The same shape as v1's deploy, with three differences that matter. Read those
first — each of them fails quietly, which is the worst way to fail.

## What is different from v1

**1. The front end is compiled, and it is not in the repository.**

v1 is Blade: the server renders the HTML and there is nothing to build. v2 is
two Vue applications compiled by Vite into `public/build`, and that folder is
gitignored. Upload the code without it and every page returns 200, renders an
empty shell, and logs nothing at all.

So: `npm run build` **on your machine**, then upload `public/build` with the
rest. Shared hosting has no Node, so it cannot be built on the server.

The deploy command checks for `public/build/manifest.json` and says so if it is
missing.

**2. The document root is `public/`, not the folder itself.**

v1 has an `index.php` at its root, so pointing a subdomain at the upload folder
works. v2 does not — its `index.php` is inside `public/`.

In hPanel → Subdomains, set the document root to
`.../public_html/testv2/public`.

**The symptom when this is wrong is `403 Forbidden` on every page.** Apache is
pointed at a folder with no `index.php` in it — Laravel's is one level down —
so there is nothing to serve and it refuses. Nothing is broken; it is looking in
the wrong place.

If the panel will not let you change it, upload
[`docs/htaccess-subdomain-root.txt`](htaccess-subdomain-root.txt) as `.htaccess`
in that folder instead. It serves everything from `public/` and refuses the
rest.

That refusing half is the point. With the document root one level too high,
`https://testv2.eswachh.in/.env` is a plain text file containing the database
password and the payment keys, and `storage/logs/laravel.log` is readable by
anyone who guesses the path. **The 403 is currently protecting you from that** —
so do not "fix" it by dropping an `index.php` at the top level.

**3. A blank database just works.**

v1's migrations were squashed into a schema dump that needs the `mysql` client.
v2 has no dump: `php artisan migrate` runs all of them in order on an empty
database, with nothing to shell out to.

---

## Setting up a fresh subdomain, start to finish

**1. Build the front end locally and upload everything.**

```bash
npm run build          # on your machine, not the server
```

Upload the project including `public/build`, excluding `node_modules`.
`vendor/` must be there too — run `composer install --no-dev` locally and
upload it if the server has no Composer.

**2. Point the subdomain's document root at `public/`.**

**3. Write the `.env`.**

```bash
cd ~/domains/eswachh.in/public_html/testv2
nano .env
```

```
APP_NAME=Eswachh
APP_ENV=production
APP_KEY=
APP_DEBUG=true
APP_URL=https://testv2.eswachh.in
APP_TIMEZONE=Asia/Kolkata

LOG_CHANNEL=stack
LOG_LEVEL=debug

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=u841499718_eswachh_v2
DB_USERNAME=
DB_PASSWORD=

SESSION_DRIVER=file
SESSION_LIFETIME=120
CACHE_STORE=file
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local

SANCTUM_STATEFUL_DOMAINS=testv2.eswachh.in

MAIL_MAILER=log
MAIL_FROM_ADDRESS="noreply@eswachh.in"
MAIL_FROM_NAME="${APP_NAME}"

RAZORPAY_ENABLED=false
RAZORPAY_KEY=
RAZORPAY_SECRET=

WHATSAPP_ENABLED=false
MSG91_AUTH_KEY=
MSG91_WHATSAPP_NUMBER=

# Only needed to import from v1. Point it at a v1 database - one with an
# `orders` table - never at a v2 one.
LEGACY_DB_DATABASE=
```

Four of those lines decide whether this works at all:

| | |
|---|---|
| `APP_TIMEZONE=Asia/Kolkata` | v1 ran on it. On UTC every imported timestamp is out by five and a half hours and the date filters find nothing. |
| `SANCTUM_STATEFUL_DOMAINS` | Must name this subdomain. Without it the cookie is not treated as first-party and **signing in fails with no error** — the form simply comes back. |
| `WHATSAPP_ENABLED=false` | With `APP_ENV=production`, this flag is the only thing standing between a test click and a message to a real customer. |
| `RAZORPAY_ENABLED=false` | Off means payments are simulated end to end, which is what a test site wants. Turn it on only with **test** keys. |

**4. Key and permissions.**

```bash
php artisan key:generate
chmod -R 775 storage bootstrap/cache
```

**5. Build the database.**

```bash
php artisan eswachh:deploy
```

It names the database and asks before touching it. Read the name.

On an empty database it creates every table, then seeds an administrator, the
site's words and the message wording. The default login is in
`database/seeders/DatabaseSeeder.php` — `ADMIN_EMAIL` / `ADMIN_PASSWORD`, both
overridable from `.env`. **Change the password immediately.**

**6. Optionally bring the v1 data across.**

```bash
php artisan eswachh:import --dry-run   # read the report first
php artisan eswachh:import
```

Set `LEGACY_DB_DATABASE` first. The command refuses a database with no `orders`
table, and refuses to run before `migrate` — see the developer guide, section 14.

**7. Open the site.** Then set `APP_DEBUG=false` and run
`php artisan config:cache`. With debug on, any error page shows the database
password.

**8. Check the rest.**

```bash
php artisan eswachh:check-integrations
```

It reports whether payments and messaging are wired up, and what is stopping
them when they are not.

---

## What still needs setting up after the site loads

A working site is not a running one. Seven jobs do the daily work, and none of
them fire until one cron entry exists.

### The scheduler — one cron entry, and that is all

In hPanel → Advanced → Cron Jobs, add a single job:

```
* * * * * cd /home/u841499718/domains/eswachh.in/public_html/testv2 && /opt/alt/php83/usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

That is the whole of it. Laravel's scheduler runs every minute, looks at what is
due, and runs only that — you never add a cron entry per job, and adding one per
job would run them twice.

**If Hostinger only offers a five-minute minimum**, that is fine here. Every
scheduled time below falls on a five-minute boundary, so nothing is missed.
Keep it in mind before adding a task at, say, `09:32` — with a five-minute cron
that task would never run at all, and nothing would report it.

### What it runs, and when

| When | Command | What it does |
|---|---|---|
| 00:10 | `eswachh:backup --keep=14` | Database backup, fourteen kept. |
| 00:20 | `eswachh:reconcile-payments --days=7` | Asks Razorpay about the last week's payments and fixes any we recorded wrongly. |
| 00:40 | `eswachh:prune-service-history` | Drops service records over fifty days old. |
| 09:30 | `eswachh:hold-overdue` | Pauses plans past the grace period set in Settings. Runs first so the next job sees the settled state. |
| 10:00 | `eswachh:send-renewal-reminders` | Chases everything expired or on hold. One message per customer per day. |
| Hourly | `eswachh:send-daily-summary` | Runs every hour, acts only in the hour set by `daily_summary_hour` in Settings. |
| Mon 06:00 | `eswachh:check-cloth-balances` | Weekly consistency check on the cloth ledger. |

### The queue — you do not need a worker

**Nothing in v2 is queued.** No `ShouldQueue`, no `dispatch()`, no `Mail::queue()`
anywhere in `app/`. The `jobs` table exists because the migration ships with
Laravel; nothing ever writes to it.

So set this and move on:

```
QUEUE_CONNECTION=sync
```

`sync` rather than `database` on purpose. Both work today, but they fail
differently the day somebody adds a queued job: on `sync` it runs inline — a
slower request, but it runs. On `database` with no worker it goes into a table
nobody drains, and nothing anywhere says so. Shared hosting has no supervisor to
keep `queue:work` alive, so `sync` is the honest setting.

### Checking it actually works

```bash
# What is due, without waiting for the cron
php artisan schedule:list

# Run the whole schedule once by hand
php artisan schedule:run

# Run one job and watch it
php artisan eswachh:send-renewal-reminders
```

The day after adding the cron, check `storage/logs/` for that day's entries and
the Messages screen for what went out. If the log is empty, the cron is not
firing — check the path and the PHP binary in the cron line first, since both
differ from what `php artisan` resolves to in your SSH session.

### Two things worth doing on the server

**Messages are held back outside production.** `Messenger::deliveryEnabled()`
requires `APP_ENV=production` *and* `WHATSAPP_ENABLED=true`. On a staging copy
keep the flag off: messages are still written to the `messages` table exactly as
they would have been sent, with the reason they were held, so you can read them
without anybody's phone ringing.

**Watch the disk.** Backups accumulate in `storage/`, and `--keep=14` is
fourteen copies of the whole database. Check `storage/app` after a fortnight.

## Deploying an update later

Same command, and it is safe to run repeatedly.

```bash
npm run build                  # locally, if anything under resources/js changed
# upload the changed files, including public/build
php artisan eswachh:deploy
```

It closes the site, runs whatever migrations are pending, rebuilds the caches in
the right order, and opens it again.

| Option | |
|---|---|
| `--pretend` | Says what it would do and changes nothing. Run this first. |
| `--force` | Skips the confirmation. |
| `--seed` | Re-runs the seeders on a database that already has tables. They create rather than replace, so nothing you have edited is overwritten. |

**If a front-end change appears to have had no effect**, it is almost always the
build: either `npm run build` was not run, or `public/build` was not uploaded.
The page will look completely normal, just old.
