<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import { api, describeError } from '@/shared/api/client';
import { useSignup } from '@/site/signup';
import { useSiteSession } from '@/site/session';

/**
 * The signup form.
 *
 * Two things it does that v1's did not.
 *
 * The price shown is the price the server worked out, fetched as the choices
 * change. v1 calculated it here, sent it back with the order, and the server
 * charged whatever arrived - so the figure on screen was also the figure being
 * trusted. Here the quote is display only; the server prices the order again
 * from the ids when it is placed.
 *
 * And the address narrows one level at a time, only offering sectors a
 * franchise actually covers, so nobody can buy a round nobody will drive.
 */

interface Option { id: string; name: string }
interface PricedOption extends Option { price: number }
interface CarModel extends Option { vehicle_type_id: string }
interface DurationOption extends Option { months: number; discount: number }
interface BundleOption extends Option { count: number; price: number }
interface SocietyOption extends Option { surcharge: number }
interface PackageOption extends PricedOption {
    summary: string;
    sections: Array<{ heading: string | null; items: string[] }>;
}

interface QuoteLine { source: string; label: string; amount: number; recurring: boolean }
interface QuoteData {
    lines: QuoteLine[];
    months: number;
    subtotal_paise: number;
    discount_paise: number;
    cloth_paise: number;
    total: number;
    per_month_paise: number;
    formatted: string;
    complete: boolean;
    warnings: string[];
}

const form = ref({
    name: '', email: '', phone: '', registration: '',
    state_id: '', city_id: '', area_id: '', sector_id: '', society_id: '',
    house_no: '', preferred_time: '09:00',
    vehicle_model_id: '', package_id: '', service_type_id: '', duration_id: '',
    cloth_bundle_id: '',
});

const { data: catalogue, isPending: loadingCatalogue } = useQuery({
    queryKey: ['public-catalogue'],
    // One request for the whole price list: the form cannot show anything
    // until it has all of it, and five round trips on a phone is a visible wait.
    queryFn: async () => (await api.get('/public/catalogue')).data.data,
    staleTime: 5 * 60 * 1000,
});

const packages = computed<PackageOption[]>(() => catalogue.value?.packages ?? []);
const serviceTypes = computed<PricedOption[]>(() => catalogue.value?.service_types ?? []);
const durations = computed<DurationOption[]>(() => catalogue.value?.durations ?? []);
const carModels = computed<CarModel[]>(() => catalogue.value?.car_models ?? []);
const bundles = computed<BundleOption[]>(() => catalogue.value?.cloth_bundles ?? []);

/** One level of the address at a time. */
function useLocations(level: string, parent: () => string) {
    return useQuery({
        queryKey: computed(() => ['locations', level, parent()]),
        enabled: computed(() => level === 'states' || !!parent()),
        queryFn: async () =>
            (await api.get('/public/locations', {
                params: { level, parent_id: parent() || undefined },
            })).data.data,
    });
}

const { data: states } = useLocations('states', () => '');
const { data: cities } = useLocations('cities', () => form.value.state_id);
const { data: areas } = useLocations('areas', () => form.value.city_id);
const { data: sectors } = useLocations('sectors', () => form.value.area_id);
const { data: societies } = useLocations('societies', () => form.value.sector_id);

// Choosing a state invalidates the city below it, and so on down.
watch(() => form.value.state_id, () => { form.value.city_id = ''; form.value.area_id = ''; form.value.sector_id = ''; form.value.society_id = ''; });
watch(() => form.value.city_id, () => { form.value.area_id = ''; form.value.sector_id = ''; form.value.society_id = ''; });
watch(() => form.value.area_id, () => { form.value.sector_id = ''; form.value.society_id = ''; });
watch(() => form.value.sector_id, () => { form.value.society_id = ''; });

const canQuote = computed(() => !!form.value.duration_id);

const { data: quote, isFetching: pricing, isError: quoteFailed, error: quoteError } = useQuery({
    queryKey: computed(() => [
        'quote', form.value.vehicle_model_id, form.value.package_id, form.value.service_type_id,
        form.value.duration_id, form.value.society_id, form.value.cloth_bundle_id,
    ]),
    enabled: canQuote,
    queryFn: async (): Promise<QuoteData> =>
        (await api.post('/public/quote', {
            vehicle_model_id: form.value.vehicle_model_id || null,
            package_id: form.value.package_id || null,
            service_type_id: form.value.service_type_id || null,
            duration_id: form.value.duration_id,
            society_id: form.value.society_id || null,
            cloth_bundle_id: form.value.cloth_bundle_id || null,
        })).data.data,
});

const selectedSociety = computed<SocietyOption | undefined>(() =>
    (societies.value ?? []).find((s: SocietyOption) => s.id === form.value.society_id),
);

/*
 * Names for the confirmation page.
 *
 * Read from the lists already loaded rather than fetched again: the customer
 * has just paid, and a page that shows "—" while it waits on a request reads
 * as something having gone wrong.
 */
const packageName = computed(
    () => packages.value.find((p) => p.id === form.value.package_id)?.name ?? 'Cleaning',
);

const durationName = computed(
    () => durations.value.find((d) => d.id === form.value.duration_id)?.name ?? '',
);

const societyName = computed(
    () => (societies.value ?? []).find((s: { id: string }) => s.id === form.value.society_id)?.name ?? '',
);

function money(value: number): string {
    return new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 0 }).format(value);
}

/**
 * Two steps behind one button: prove the number, then place the order.
 *
 * The order is placed by id, never by price. Whatever is on screen is a quote;
 * the server works the amount out again from these same choices.
 */
/**
 * Signed in already? Then this form is almost certainly the wrong page, so it
 * is replaced by a way through to their own plans - with a way past it for the
 * rare case of buying for somebody else.
 */
const { session: signedIn } = useSiteSession();
const startAnyway = ref(false);
const session = computed(() => (startAnyway.value ? null : signedIn.value));

const signup = useSignup(form);

const buttonLabel = computed(() => {
    if (signup.busy.value) return 'Please wait…';

    return signup.stage.value === 'code' ? `Pay ${quote.value?.formatted ?? ''}` : 'Continue';
});

function submit() {
    /*
     * Everything wrong, said at once.
     *
     * Somebody who tabs past a field never blurs it, so its message would stay
     * hidden until the server refused the whole form. Pressing on marks them
     * all touched, and the code is not sent until the form could actually be
     * accepted - there is no point spending a message on a form we already know
     * will be turned down.
     */
    if (!signup.ready.value) {
        signup.touchEverything();
        return;
    }

    if (signup.stage.value === 'code') {
        signup.placeOrder();
        return;
    }

    signup.sendCode();
}
</script>

<template>
    <!--
        Paid. A page of its own rather than a green line beside a form they
        have finished with: what somebody wants at this moment is proof of what
        they bought and what happens next, and a form still on screen invites
        them to fill it in again.
    -->
    <div v-if="signup.stage.value === 'done'" class="mx-auto max-w-2xl px-4 py-12">
        <div class="rounded-lg border border-ok bg-ok-soft p-6 text-center">
            <svg class="mx-auto h-12 w-12 text-ok" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M20 6 9 17l-5-5" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            <h1 class="mt-3 text-2xl font-bold tracking-tight text-ink">Payment received</h1>
            <p class="mt-1 text-body">
                Thank you, {{ form.name }}. Your cleaning plan is live.
            </p>
        </div>

        <div class="mt-6 rounded-lg border border-line bg-surface">
            <h2 class="border-b border-line px-5 py-3 text-sm font-semibold uppercase tracking-wide text-muted">
                Your order
            </h2>

            <dl class="divide-y divide-line text-sm">
                <div v-if="signup.receipt.value?.invoice_number" class="flex justify-between gap-4 px-5 py-3">
                    <dt class="text-muted">Invoice</dt>
                    <dd class="font-medium tabular-nums text-ink">{{ signup.receipt.value.invoice_number }}</dd>
                </div>
                <div class="flex justify-between gap-4 px-5 py-3">
                    <dt class="text-muted">Car</dt>
                    <dd class="font-medium uppercase text-ink">{{ form.registration }}</dd>
                </div>
                <div class="flex justify-between gap-4 px-5 py-3">
                    <dt class="text-muted">Plan</dt>
                    <dd class="text-ink">{{ packageName }}{{ durationName ? `, ${durationName}` : '' }}</dd>
                </div>
                <div class="flex justify-between gap-4 px-5 py-3">
                    <dt class="text-muted">Where</dt>
                    <dd class="text-end text-ink">
                        {{ form.house_no }}<template v-if="societyName">, {{ societyName }}</template>
                    </dd>
                </div>
                <div class="flex justify-between gap-4 px-5 py-3">
                    <dt class="text-muted">Paid</dt>
                    <dd class="text-lg font-bold tabular-nums text-ink">
                        {{ money(signup.receipt.value?.amount ?? quote?.total ?? 0) }}
                    </dd>
                </div>
            </dl>
        </div>

        <div class="mt-6 rounded-lg border border-line bg-surface p-5 text-sm text-body">
            <h2 class="mb-2 font-semibold text-ink">What happens next</h2>
            <ol class="ms-4 list-decimal space-y-1">
                <li>We assign a cleaner to your car and tell you who it is.</li>
                <li>Cleaning starts from the next round, and you get a message each day it is done.</li>
                <li>
                    You can see your plan any time — sign in with
                    <span class="font-medium text-ink">{{ form.phone }}</span> and we send a code.
                    There is no password.
                </li>
            </ol>

            <a
                href="/login"
                class="mt-4 inline-block rounded bg-accent px-5 py-2.5 text-sm font-medium text-on-accent transition hover:brightness-110"
            >
                See my plan
            </a>
        </div>

        <p class="mt-4 text-center text-xs text-faint">
            A copy of this has gone to {{ form.phone }}<template v-if="form.email"> and {{ form.email }}</template>.
        </p>
    </div>

    <div v-else class="mx-auto max-w-6xl px-4 py-8">
        <h1 class="text-2xl font-bold tracking-tight text-ink">Start a subscription</h1>
        <p class="mt-1 max-w-prose text-body">
            Tell us where the car is kept and how often you want it cleaned. The price updates as you choose.
        </p>

        <!--
            Somebody already signed in gets sent to their own pages instead of a
            blank form. The server would refuse the signup anyway - their number
            is registered - but finding that out after filling the whole thing
            in reads as though the site had forgotten them.
        -->
        <div v-if="session" class="mt-8 rounded-lg border border-line bg-surface p-6">
            <h2 class="text-lg font-semibold text-ink">You are already signed in</h2>
            <p class="mt-1 max-w-prose text-body">
                Adding another car is quicker from your own pages: your address and phone number
                are already there, and there is no code to wait for.
            </p>

            <div class="mt-4 flex flex-wrap gap-3">
                <a
                    :href="session.home"
                    class="rounded bg-accent px-5 py-2.5 text-sm font-semibold text-on-accent transition hover:brightness-110"
                >
                    Add a car from my plans
                </a>

                <button
                    type="button"
                    class="rounded border border-line-strong px-5 py-2.5 text-sm font-semibold text-body transition hover:bg-sunk"
                    @click="startAnyway = true"
                >
                    Start a plan for somebody else
                </button>
            </div>

            <!--
                Said plainly, because this form genuinely cannot serve them: it
                proves a mobile number with a code and then refuses numbers it
                already knows, so an existing customer would be told their own
                number was taken.
            -->
            <p class="mt-3 text-xs text-faint">
                This form is for a new customer, and asks for a code sent to a mobile number we do
                not already hold.
            </p>
        </div>

        <div v-else-if="loadingCatalogue" class="mt-8 text-muted">Loading the price list…</div>

        <form v-else class="mt-6 grid gap-6 lg:grid-cols-[1fr_20rem]" @submit.prevent="submit">
            <div class="flex flex-col gap-6">
                <!-- Who -->
                <section class="rounded-lg border border-line bg-surface p-4">
                    <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-muted">Your details</h2>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="mb-1 block text-sm font-medium text-body">Name</span>
                            <input v-model.trim="form.name" type="text" required @blur="signup.touch('name')" class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent" />
                            <p v-if="signup.errorFor('name')" class="mt-1 text-xs text-crit">{{ signup.errorFor('name') }}</p>
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-sm font-medium text-body">Mobile number</span>
                            <input v-model.trim="form.phone" type="tel" inputmode="numeric" maxlength="10" required @blur="signup.touch('phone')" class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink tabular-nums focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent" />
                            <p v-if="signup.errorFor('phone')" class="mt-1 text-xs text-crit">{{ signup.errorFor('phone') }}</p>
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-sm font-medium text-body">Email</span>
                            <input v-model.trim="form.email" type="email" @blur="signup.touch('email')" class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent" />
                            <p v-if="signup.errorFor('email')" class="mt-1 text-xs text-crit">{{ signup.errorFor('email') }}</p>
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-sm font-medium text-body">Car number</span>
                            <input v-model.trim="form.registration" type="text" required placeholder="UP16AB1234" @blur="signup.touch('registration')" class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm uppercase text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent" />
                            <p v-if="signup.errorFor('registration')" class="mt-1 text-xs text-crit">{{ signup.errorFor('registration') }}</p>
                        </label>
                    </div>
                </section>

                <!-- Where -->
                <section class="rounded-lg border border-line bg-surface p-4">
                    <h2 class="mb-1 text-sm font-semibold uppercase tracking-wide text-muted">Where the car is kept</h2>
                    <p class="mb-3 text-xs text-muted">Only areas we currently service are listed.</p>

                    <div class="grid gap-3 sm:grid-cols-3">
                        <label class="block">
                            <span class="mb-1 block text-sm font-medium text-body">State</span>
                            <select v-model="form.state_id" required class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">
                                <option value="">Choose…</option>
                                <option v-for="o in states ?? []" :key="o.id" :value="o.id">{{ o.name }}</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-sm font-medium text-body">City</span>
                            <select v-model="form.city_id" :disabled="!form.state_id" required class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink disabled:opacity-50 focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">
                                <option value="">Choose…</option>
                                <option v-for="o in cities ?? []" :key="o.id" :value="o.id">{{ o.name }}</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-sm font-medium text-body">Area</span>
                            <select v-model="form.area_id" :disabled="!form.city_id" required class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink disabled:opacity-50 focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">
                                <option value="">Choose…</option>
                                <option v-for="o in areas ?? []" :key="o.id" :value="o.id">{{ o.name }}</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-sm font-medium text-body">Sector</span>
                            <select v-model="form.sector_id" :disabled="!form.area_id" required class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink disabled:opacity-50 focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">
                                <option value="">Choose…</option>
                                <option v-for="o in sectors ?? []" :key="o.id" :value="o.id">{{ o.name }}</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-sm font-medium text-body">Society</span>
                            <select v-model="form.society_id" :disabled="!form.sector_id" required class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink disabled:opacity-50 focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">
                                <option value="">Choose…</option>
                                <option v-for="o in societies ?? []" :key="o.id" :value="o.id">{{ o.name }}</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-sm font-medium text-body">Flat / house no.</span>
                            <input v-model.trim="form.house_no" type="text" required @blur="signup.touch('house_no')" class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent" />
                            <p v-if="signup.errorFor('house_no')" class="mt-1 text-xs text-crit">{{ signup.errorFor('house_no') }}</p>
                        </label>
                    </div>

                    <p v-if="selectedSociety && selectedSociety.surcharge > 0" class="mt-3 rounded bg-warn-soft px-3 py-2 text-sm text-warn">
                        {{ selectedSociety.name }} carries a {{ money(selectedSociety.surcharge) }} monthly surcharge,
                        already included in the price on the right.
                    </p>

                    <label class="mt-3 block sm:w-48">
                        <span class="mb-1 block text-sm font-medium text-body">Preferred time</span>
                        <input v-model="form.preferred_time" type="time" class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink tabular-nums focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent" />
                    </label>
                </section>

                <!-- What -->
                <section class="rounded-lg border border-line bg-surface p-4">
                    <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-muted">Your plan</h2>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="mb-1 block text-sm font-medium text-body">Car model</span>
                            <select v-model="form.vehicle_model_id" required class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">
                                <option value="">Choose…</option>
                                <option v-for="o in carModels" :key="o.id" :value="o.id">{{ o.name }}</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-sm font-medium text-body">Package</span>
                            <select v-model="form.package_id" required class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">
                                <option value="">Choose…</option>
                                <option v-for="o in packages" :key="o.id" :value="o.id">{{ o.name }}</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-sm font-medium text-body">Interior cleaning</span>
                            <select v-model="form.service_type_id" required class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">
                                <option value="">Choose…</option>
                                <option v-for="o in serviceTypes" :key="o.id" :value="o.id">{{ o.name }}</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-sm font-medium text-body">How long</span>
                            <select v-model="form.duration_id" required class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">
                                <option value="">Choose…</option>
                                <option v-for="o in durations" :key="o.id" :value="o.id">
                                    {{ o.name }}<template v-if="o.discount > 0"> — save {{ money(o.discount) }}</template>
                                </option>
                            </select>
                        </label>
                        <label v-if="bundles.length" class="block sm:col-span-2">
                            <span class="mb-1 block text-sm font-medium text-body">Cloth bundle (optional)</span>
                            <select v-model="form.cloth_bundle_id" class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">
                                <option value="">No thanks</option>
                                <option v-for="o in bundles" :key="o.id" :value="o.id">
                                    {{ o.name }} — {{ money(o.price) }} once
                                </option>
                            </select>
                        </label>
                    </div>
                </section>
            </div>

            <!-- The price, worked out on the server -->
            <aside class="lg:sticky lg:top-20 lg:self-start">
                <div class="rounded-lg border border-line-strong bg-surface p-4">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-muted">Your price</h2>

                    <p v-if="!canQuote" class="mt-3 text-sm text-muted">
                        Choose how long you want the service for and the price appears here.
                    </p>

                    <p v-else-if="quoteFailed" class="mt-3 rounded bg-crit-soft px-3 py-2 text-sm text-crit">
                        {{ describeError(quoteError).message }}
                    </p>

                    <template v-else-if="quote">
                        <dl class="mt-3 flex flex-col gap-1.5 text-sm">
                            <div v-for="(line, i) in quote.lines" :key="i" class="flex items-baseline justify-between gap-3">
                                <dt class="text-body">
                                    {{ line.label }}
                                    <span v-if="line.recurring && quote.months > 1" class="text-xs text-faint">
                                        × {{ quote.months }}
                                    </span>
                                </dt>
                                <dd class="tabular-nums" :class="line.amount < 0 ? 'text-ok' : 'text-ink'">
                                    {{ line.amount < 0 ? '−' : '' }}{{ money(Math.abs(line.amount)) }}
                                </dd>
                            </div>
                        </dl>

                        <div class="mt-3 flex items-baseline justify-between border-t border-line pt-3">
                            <span class="font-semibold text-ink">Total</span>
                            <span class="text-xl font-bold tabular-nums text-ink">{{ quote.formatted }}</span>
                        </div>

                        <p v-if="quote.months > 1" class="mt-1 text-right text-xs text-muted">
                            {{ money(quote.per_month_paise / 100) }} a month
                        </p>

                        <p v-for="w in quote.warnings" :key="w" class="mt-2 rounded bg-warn-soft px-3 py-2 text-xs text-warn">
                            {{ w }}
                        </p>

                        <span v-if="pricing" class="mt-2 block text-xs text-faint">updating…</span>
                    </template>

                    <!--
                        The code step. Shown here beside the price rather than
                        further up the form: by now they have read the total,
                        and the next thing they do is pay it.
                    -->
                    <label v-if="signup.stage.value === 'code'" class="mt-4 block">
                        <span class="mb-1 block text-sm font-medium text-body">Code sent to {{ form.phone }}</span>
                        <input
                            v-model.trim="signup.code.value"
                            type="text"
                            inputmode="numeric"
                            maxlength="6"
                            autocomplete="one-time-code"
                            class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-center text-lg tracking-[0.4em] tabular-nums text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                        />
                        <span v-if="signup.fieldErrors.value.code" class="mt-1 block text-xs text-crit">
                            {{ signup.fieldErrors.value.code[0] }}
                        </span>
                    </label>

                    <button
                        type="submit"
                        class="mt-4 w-full rounded bg-accent px-4 py-2.5 text-sm font-semibold text-on-accent transition hover:brightness-110 disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-accent"
                        :disabled="!quote || signup.busy.value || (signup.stage.value === 'code' && signup.code.value.length < 6)"
                    >
                        {{ buttonLabel }}
                    </button>

                    <div v-if="signup.stage.value === 'code'" class="mt-2 flex flex-wrap items-center justify-center gap-3 text-xs">
                        <button
                            type="button"
                            class="text-accent underline disabled:cursor-not-allowed disabled:text-muted disabled:no-underline"
                            :disabled="signup.busy.value || signup.cooldown.remaining.value > 0"
                            @click="signup.sendCode()"
                        >
                            {{ signup.cooldown.remaining.value > 0 ? `Resend in ${signup.cooldown.remaining.value}s` : 'Send it again' }}
                        </button>

                        <button
                            type="button"
                            class="text-muted underline hover:text-ink"
                            @click="signup.stage.value = 'details'; signup.code.value = ''; signup.cooldown.stop()"
                        >
                            Change my details
                        </button>
                    </div>

                    <p v-if="signup.error.value" class="mt-3 rounded bg-crit-soft px-3 py-2 text-sm text-crit" role="alert">
                        {{ signup.error.value }}
                    </p>

                    <p class="mt-3 text-xs text-faint">
                        This price is worked out by us, not by your browser — it is confirmed again before
                        anything is charged.
                    </p>
                </div>
            </aside>
        </form>
    </div>
</template>
