import type { QueryClient } from '@tanstack/vue-query';

/**
 * What else goes out of date when something is saved.
 *
 * Reference lists are cached hard on purpose - the cleaners, the price list,
 * the geography - because they are read on nearly every screen and change a few
 * times a month. The cost of that is a cache which is right about everybody
 * else's edits and wrong about your own: add a cleaner on People, walk to
 * Subscriptions, and the picker does not offer them for another five minutes.
 * From the office's side the cleaner simply was not saved, so they save them
 * again.
 *
 * Each screen used to clear only its own keys, which is exactly the half that
 * was never the problem - the list you were looking at refetched, and the four
 * other screens reading the same records did not. Naming the fan-out here means
 * a screen only has to say what kind of thing it changed.
 */
const DEPENDENTS = {
    /** Staff: their own list, and every picker that offers a cleaner. */
    people: [['users'], ['bulk', 'cleaners'], ['cleaners'], ['complaint-options'], ['round']],

    /*
     * A master is a reference list, and reference lists are read everywhere.
     * A new society changes what a customer form offers; a new package or
     * duration changes what a plan can be sold on, and what it costs.
     */
    masters: [['masters'], ['public-catalogue'], ['public-content'], ['locations'], ['catalogue'], ['complaint-options']],

    /*
     * `['customer']` matches the open detail panel by prefix, so adding a car
     * refreshes both the panel and the count in the table behind it.
     */
    customers: [['customers'], ['customer']],

    /*
     * A plan changing takes the round with it: the cleaner's list for today is
     * built from exactly these rows, and reassigning a car used to leave it
     * showing the old name until somebody reloaded.
     */
    subscriptions: [['subscriptions'], ['subscription'], ['round']],

    /*
     * Money moves more than the ledger. A captured payment extends the period
     * and writes the paid figure the subscriptions column shows, prints an
     * invoice, and takes the plan off the not-finished list.
     */
    payments: [
        ['payments'], ['payment-detail'], ['invoice'], ['abandoned'],
        ['subscriptions'], ['subscription'],
    ],

    /*
     * A custom role appears in the picker on People, so creating one and then
     * going there to use it found a list without it in.
     */
    roles: [['roles'], ['users']],

    /*
     * Cloth movements change the balance the subscriptions list prints in its
     * own column, so the two disagreed until a reload.
     */
    cloth: [['cloth'], ['subscriptions']],
} as const;

export type Changed = keyof typeof DEPENDENTS;

/**
 * Mark everything that depends on what just changed as out of date.
 *
 * Prefix matching does the rest: clearing `['cleaners']` also clears
 * `['cleaners', <plan id>]`, so the per-plan pickers do not each need naming.
 *
 * Awaited together rather than in turn - these are cache operations, and a
 * screen should not sit through six round trips before it redraws.
 */
export async function refreshAfter(client: QueryClient, ...changed: Changed[]): Promise<void> {
    await Promise.all(
        changed.flatMap((what) => DEPENDENTS[what].map(
            (key) => client.invalidateQueries({ queryKey: [...key] }),
        )),
    );
}
