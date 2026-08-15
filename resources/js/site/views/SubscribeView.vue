<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import { api, describeError } from '@/shared/api/client';
import { useSignup } from '@/site/signup';

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

function money(value: number): string {
    return new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 0 }).format(value);
}

/**
 * Two steps behind one button: prove the number, then place the order.
 *
 * The order is placed by id, never by price. Whatever is on screen is a quote;
 * the server works the amount out again from these same choices.
 */
const signup = useSignup(form);

const buttonLabel = computed(() => {
    if (signup.busy.value) return 'Please wait…';

    return signup.stage.value === 'code' ? `Pay ${quote.value?.formatted ?? ''}` : 'Continue';
});

function submit() {
    if (signup.stage.value === 'code') {
        signup.placeOrder();
        return;
    }

    signup.sendCode();
}
</script>

<template>
    <div class="mx-auto max-w-6xl px-4 py-8">
        <h1 class="text-2xl font-bold tracking-tight text-ink">Start a subscription</h1>
        <p class="mt-1 max-w-prose text-body">
            Tell us where the car is kept and how often you want it cleaned. The price updates as you choose.
        </p>

        <div v-if="loadingCatalogue" class="mt-8 text-muted">Loading the price list…</div>

        <form v-else class="mt-6 grid gap-6 lg:grid-cols-[1fr_20rem]" @submit.prevent="submit">
            <div class="flex flex-col gap-6">
                <!-- Who -->
                <section class="rounded-lg border border-line bg-surface p-4">
                    <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-muted">Your details</h2>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="mb-1 block text-sm font-medium text-body">Name</span>
                            <input v-model.trim="form.name" type="text" required class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent" />
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-sm font-medium text-body">Mobile number</span>
                            <input v-model.trim="form.phone" type="tel" inputmode="numeric" maxlength="10" required class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink tabular-nums focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent" />
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-sm font-medium text-body">Email</span>
                            <input v-model.trim="form.email" type="email" required class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent" />
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-sm font-medium text-body">Car number</span>
                            <input v-model.trim="form.registration" type="text" required placeholder="UP16AB1234" class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm uppercase text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent" />
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
                            <input v-model.trim="form.house_no" type="text" required class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent" />
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
                        v-if="signup.stage.value !== 'done'"
                        type="submit"
                        class="mt-4 w-full rounded bg-accent px-4 py-2.5 text-sm font-semibold text-on-accent transition hover:brightness-110 disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-accent"
                        :disabled="!quote || signup.busy.value || (signup.stage.value === 'code' && signup.code.value.length < 6)"
                    >
                        {{ buttonLabel }}
                    </button>

                    <button
                        v-if="signup.stage.value === 'code'"
                        type="button"
                        class="mt-2 w-full text-center text-xs text-muted underline hover:text-ink"
                        @click="signup.stage.value = 'details'; signup.code.value = ''"
                    >
                        Change my details
                    </button>

                    <p v-if="signup.error.value" class="mt-3 rounded bg-crit-soft px-3 py-2 text-sm text-crit" role="alert">
                        {{ signup.error.value }}
                    </p>

                    <p v-else-if="signup.notice.value" class="mt-3 rounded bg-ok-soft px-3 py-2 text-sm text-ok">
                        {{ signup.notice.value }}
                    </p>

                    <p v-if="signup.stage.value === 'done'" class="mt-3 text-xs text-faint">
                        You can sign in any time with a code sent to {{ form.phone }} to see your plan.
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
