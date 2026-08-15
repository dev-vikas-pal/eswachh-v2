# How this code is arranged

Everything is filed by **who it is for**, not by what kind of file it is. There
are three audiences and one shared middle, and the same four names appear on
both sides of the wire.

| Audience   | Backend                            | Front end            |
|------------|------------------------------------|----------------------|
| The office | `app/Http/Controllers/Api/V1/Admin` | `resources/js/admin` |
| A customer | `.../Api/V1/Portal`                 | `resources/js/portal` |
| A visitor  | `.../Api/V1/Site`                   | `resources/js/site`  |
| More than one | `.../Api/V1/Shared`              | `resources/js/shared` |

## Why this split and not the usual one

Grouping by resource — a `SubscriptionController` holding everything anyone can
do to a subscription — hides the only question that matters here: *who is
asking*. A customer holds `view.subscription` so they can look at their own
plan. An ability cannot say "their own"; only a query can. Filing by audience
puts that question in the folder name.

`Shared` is the important one. Those are the controllers reached by both the
office and a customer, so each carries a row-level check inside it
(`RestrictsToOwnRecords`). If one of them is ever moved into `Admin`, that check
starts to look redundant to whoever edits it next — which is exactly how a
customer ends up reading the whole branch's payments.

## What is not in this table

- **`app/Domain/*`** — the business itself: pricing, billing, messaging, the
  daily round, the complaint workflow. No HTTP, no request objects. A controller
  validates and delegates; the rules live here so they read the same whether a
  person, a scheduled job or a test called them.
- **`app/Support/*`** — the plumbing that is not a business rule: branch
  scoping, sorting whitelists, settings, numbering.
- **`app/Models`** — one flat folder on purpose. Models are referenced from
  every module, and a `Subscription` filed under `Admin` would be a lie.

## The two front-end bundles

`vite.config.ts` builds `site/main.ts` and `admin/main.ts` separately. A visitor
reading the price list does not download the reports screen or the masters form.
The portal lives inside the admin bundle because it needs the session and the
auth store; it has its own layout and its own routes, and the router keeps the
two apart by role, not by ability:

```ts
if (auth.isCustomer !== (to.meta.customer === true)) return home;
```

## Where logic goes

Not in `.vue` files. Anything with a decision in it — the signup flow, row
selection, the subscriptions API — is a `.ts` module beside the views that use
it (`site/signup.ts`, `admin/shared/useRowSelection.ts`,
`admin/shared/subscriptions.api.ts`, `portal/portal.api.ts`). A component binds
and renders; it does not decide.

## Two rules that are not negotiable

**No amount ever comes from a request.** Every price is worked out from the
masters by `Domain\Pricing\PriceBook`, on the server, at the moment it is
charged. The figure the browser shows is a quote and is thrown away. The
previous system read `final_price` from the form.

**Nothing is marked paid except a verified callback.** Signup, renewal and
top-up all open a payment and stop. `Domain\Billing\RecordPayment` moves it, and
only after the Razorpay signature checks out and the gateway confirms what it
holds.

## Adding a route

1. Decide who it is for. That is the folder.
2. If the answer is "both the office and a customer", it is `Shared`, and it
   needs an ownership check — `restrictToOwnRecords()` for a list,
   `ownsRecord()` for a single row, returning **404 and not 403**, because
   refusing must not confirm that the record exists.
3. Abilities go on the route or at the top of the method. They answer *may this
   person use this feature*, never *whose rows are these*.
