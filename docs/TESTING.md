# How to test it

Two places: your own machine, and the test site on Hostinger. The same checks
work in both. Nothing here can message a real customer or take real money,
provided you check step 0 first.

---

## Step 0 — the safety check, every time

Run this before anything else, on whichever machine you are about to test on:

```bash
php artisan eswachh:check-integrations
```

It tells you whether payments and messaging are live, and what is stopping each
one. It ends with a red line and a non-zero exit code when something is not
configured — that is the command doing its job, not failing.

For a test site you want both **off**. If you would rather check by hand:

```bash
grep -E "APP_ENV|WHATSAPP_ENABLED|RAZORPAY_ENABLED" .env
```

| | Your machine | Hostinger test site |
|---|---|---|
| `APP_ENV` | `local` | `production` |
| `WHATSAPP_ENABLED` | anything | **`false`** |
| `RAZORPAY_ENABLED` | `false` | `false`, or true with **test** keys |

**On your own machine nothing can be sent at all** — messages are blocked
outside production whatever the settings say. On the Hostinger test site
`APP_ENV` is `production`, so `WHATSAPP_ENABLED=false` is the only thing
standing between a test click and a real customer's phone. Check it.

> **This is not hypothetical.** The `.env` on the development machine has
> `WHATSAPP_ENABLED=true` and a real MSG91 key in it. It is harmless there
> because `APP_ENV=local` blocks delivery. Copy that file to a server where
> `APP_ENV=production` and every imported customer is messaged. Never copy an
> `.env` between machines — write the server's by hand.

After changing `.env`, always run `php artisan config:cache`.

---

## Testing messages

You never need to send a message to test one. Every message is written to the
`messages` table exactly as it would have been sent, with the reason it was held
back.

**1. Run the job by hand:**

```bash
php artisan eswachh:send-renewal-reminders --dry-run   # lists who, sends nothing, writes nothing
php artisan eswachh:send-renewal-reminders             # writes the messages, still sends nothing
```

**2. Look at them:** open **Messages** in the office. Each row shows the
customer, the number, what it was about, and the wording. The status will say
*"Not sent (delivery off)"* with the reason.

**3. Read the wording:** click **Read** on any row. This is exactly what the
customer would have received.

### Testing a date without waiting for it

The two message jobs take `--date`, so you can see what will happen tomorrow, or
next week, without changing your computer's clock:

```bash
php artisan eswachh:send-renewal-reminders --dry-run --date=2026-09-10
php artisan eswachh:send-daily-summary --date=2026-09-10
```

The others do not take a date. What each one accepts:

| Job | Options |
|---|---|
| `eswachh:send-renewal-reminders` | `--date` `--overdue-every` `--hold-every` `--dry-run` |
| `eswachh:send-daily-summary` | `--date` |
| `eswachh:hold-overdue` | `--grace` `--limit` `--dry-run` |
| `eswachh:prune-service-history` | `--days` `--dry-run` |
| `eswachh:reconcile-payments` | `--days` `--dry-run` |
| `eswachh:check-cloth-balances` | `--repair` |
| `eswachh:backup` | `--keep` `--no-prune` |

### What to expect

- A plan that has **expired** is chased every day until renewed.
- A plan **on hold** is chased every day until renewed or marked Ended.
- A plan that has **not yet expired** is never messaged.

If the number looks wrong, compare it with the Dashboard: **Expired** plus **On
hold** is the total that should ever be chased.

---

## Testing payments

With `RAZORPAY_ENABLED=false` the gateway is skipped entirely and the payment is
completed by the system. Nothing reaches a bank.

1. Open the public site, choose a plan, and go through signup.
2. There is no card form — it completes and shows the receipt page.
3. Check **Payments** in the office: the payment is there, with an invoice
   number.
4. Click the receipt link. It opens without signing in.

To test the **real** Razorpay window, put your Razorpay **test** keys in and set
`RAZORPAY_ENABLED=true`. Their test card numbers are in the Razorpay dashboard.
Live keys on a test site means real customers paying into an account that never
settles — do not.

### Testing a renewal

1. Office → **Subscriptions** → find a plan → **Actions** → **Edit this plan**.
2. Set the end date to yesterday and save.
3. Public site → **Renew** → type the car number → check the price is right.
4. Pay. The new dates should carry on from the old end date, not from today.

---

## Testing the nightly jobs

```bash
php artisan schedule:list      # what is scheduled and when it next runs
php artisan schedule:run       # run everything that is due right now
```

To run one job on its own, take its name from `schedule:list` and run it
directly. Every one of them accepts `--dry-run`.

### On Hostinger, checking the cron itself is alive

The jobs only run if the cron entry is working. Make it write to a file for the
first day:

```
* * * * * cd /home/u841499718/domains/eswachh.in/public_html/testv2 && /opt/alt/php83/usr/bin/php artisan schedule:run >> /home/u841499718/cron-test.log 2>&1
```

Two minutes later:

```bash
ls -la ~/cron-test.log
```

**If the file exists, cron is working** — even if it is empty, or says "No
scheduled commands are ready to run". That is the correct output when nothing is
due that minute.

If the file never appears, the problem is the path or the PHP binary in the cron
line. Copy both from a working SSH session.

Once you are happy, change the ending to `>> /dev/null 2>&1` so it does not grow.

---

## Testing the switches in Settings

Change one, save, and check it took effect. These three hide things everywhere
at once:

| Setting | Turn it off, then check |
|---|---|
| Publish the advice blog | Advice gone from the public menu; Blog gone from the office menu; `/blog` sends you home |
| Show the team page | Team gone from the public menu; `/team` sends you home |
| Offer the cloth ironing service | Cloths gone from the office menu; the cloth choice gone from the signup form |

You should not need to reload — saving reloads the session for you. If a change
seems not to have taken, that is the first thing to suspect.

---

## Before calling it tested

```bash
php artisan test          # 435 tests, about 70 seconds
npx vue-tsc --noEmit      # the front end, about 30 seconds
```

Both should print nothing but success. Run them on your own machine, not on
Hostinger — the suite drops and rebuilds tables, and shared hosting is slow.

---

## What is different on Hostinger

Everything above works the same, with three exceptions:

1. **Use the full PHP path**: `/opt/alt/php83/usr/bin/php artisan …`
2. **`APP_ENV` is `production`**, so messages *can* be sent. `WHATSAPP_ENABLED=false`
   is what stops them.
3. **Front-end changes need `npm run build` on your machine first**, then upload
   `public/build`. There is no Node on the server. If a change appears to have
   had no effect, this is why — the page will look completely normal, just old.

---

## Quick reference

```bash
php artisan eswachh:check-integrations           # is anything live?
php artisan eswachh:send-renewal-reminders --dry-run
php artisan eswachh:hold-overdue --dry-run
php artisan schedule:list
php artisan test
npx vue-tsc --noEmit
php artisan config:clear                         # after any .env change
```
