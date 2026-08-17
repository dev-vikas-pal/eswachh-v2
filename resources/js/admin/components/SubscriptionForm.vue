<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useQuery, useQueryClient } from '@tanstack/vue-query';
import { api, describeError } from '@/shared/api/client';
import { useAuthStore } from '@/shared/stores/auth';

/**
 * Creating or editing a plan.
 *
 * The price shown is the server's, refreshed as the choices change. It is
 * display only: the server prices the plan again from these same ids when it is
 * saved, so nothing here can decide an amount.
 */
const props = defineProps<{ subscriptionId?: string | null }>();
const emit = defineEmits<{ (e: 'close'): void; (e: 'saved'): void }>();

const queryClient = useQueryClient();

/** Only an administrator may renumber a car. Checked again on the server. */
const isAdmin = computed(() => useAuthStore().user?.role.value === 'super_admin');

const form = ref({
    customer_id: '',
    registration: '',
    vehicle_model_id: '',
    package_id: '',
    service_type_id: '',
    duration_id: '',
    cloth_bundle_id: '',
    agreed_amount: '' as string | number,
    agreed_reason: '',
    // v1 kept these on the order form too.
    period_start: '',
    period_end: '',
    status: '',
    assigned_cleaner_id: '',
    reprice: false,

    /*
     * The payment details v1 kept on its order form. They belong to the plan's
     * latest payment, not to the plan, so correcting one here corrects the
     * receipt and the revenue report with it.
     */
    payment_method: '',
    payment_reference: '',
    payment_paid_at: '',
});

/** What was last paid, so the form can label the block honestly. */
const lastPayment = computed(() => existing.value?.last_payment ?? null);

const customerSearch = ref('');
const chosenCustomer = ref<{ id: string; name: string; phone: string | null; sector: string | null } | null>(null);
const saving = ref(false);
const error = ref<string | null>(null);

const { data: catalogue } = useQuery({
    queryKey: ['public-catalogue'],
    queryFn: async () => (await api.get('/public/catalogue')).data.data,
    staleTime: 5 * 60 * 1000,
});

const { data: customers } = useQuery({
    queryKey: computed(() => ['customers', 'picker', customerSearch.value]),
    // Two characters before searching: one letter matches most of the book.
    enabled: computed(() => customerSearch.value.length >= 2 && !chosenCustomer.value),
    queryFn: async () => (await api.get('/customers', {
        params: { search: customerSearch.value, per_page: 10 },
    })).data.data,
});

const { data: cleaners } = useQuery({
    queryKey: ['bulk', 'cleaners'],
    queryFn: async () => (await import('@/admin/shared/subscriptions.api')).cleanersInSectors(),
    staleTime: 5 * 60 * 1000,
});

/** A quote, refreshed whenever the plan changes. Display only. */
const { data: quote, isFetching: pricing } = useQuery({
    queryKey: computed(() => [
        'quote', form.value.vehicle_model_id, form.value.package_id,
        form.value.service_type_id, form.value.duration_id, form.value.cloth_bundle_id,
    ]),
    enabled: computed(() => !!form.value.duration_id),
    queryFn: async () => (await api.post('/pricing/quote', {
        vehicle_model_id: form.value.vehicle_model_id || null,
        package_id: form.value.package_id || null,
        service_type_id: form.value.service_type_id || null,
        duration_id: form.value.duration_id,
        cloth_bundle_id: form.value.cloth_bundle_id || null,
    })).data.data,
});

/**
 * Load the plan being edited.
 *
 * Every field v1 kept on its order form, so somebody correcting a package or a
 * cleaning type does not have to go looking for a second screen.
 */
const { data: existing } = useQuery({
    queryKey: computed(() => ['subscription', props.subscriptionId]),
    enabled: computed(() => !!props.subscriptionId),
    queryFn: async () => (await api.get(`/subscriptions/${props.subscriptionId}`)).data.data,
});

watch(existing, (plan) => {
    if (!plan) return;

    form.value = {
        ...form.value,
        registration: plan.vehicle?.registration ?? '',
        vehicle_model_id: plan.vehicle?.vehicle_model_id ?? '',
        package_id: plan.package_id ?? '',
        service_type_id: plan.service_type_id ?? '',
        duration_id: plan.duration_id ?? '',
        cloth_bundle_id: plan.cloth_bundle_id ?? '',
        period_start: plan.period?.start ?? '',
        period_end: plan.period?.end ?? '',
        status: plan.status?.value ?? '',
        assigned_cleaner_id: plan.vehicle?.cleaner?.id ?? '',

        payment_method: plan.last_payment?.method ?? '',
        payment_reference: plan.last_payment?.reference ?? plan.last_payment?.gateway_payment_id ?? '',
        // The server sends a full timestamp; the date input wants a day.
        payment_paid_at: (plan.last_payment?.paid_at ?? '').slice(0, 10),
    };
});

watch(chosenCustomer, (c) => {
    form.value.customer_id = c?.id ?? '';
});

function money(rupees: number): string {
    return new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR' }).format(rupees);
}

async function save() {
    saving.value = true;
    error.value = null;

    const payload: Record<string, unknown> = {
        customer_id: form.value.customer_id,
        registration: form.value.registration,
        vehicle_model_id: form.value.vehicle_model_id || null,
        package_id: form.value.package_id,
        service_type_id: form.value.service_type_id,
        duration_id: form.value.duration_id,
        cloth_bundle_id: form.value.cloth_bundle_id || null,
    };

    // Sent only when a different figure was actually agreed. An empty box must
    // not become a zero rupee plan.
    if (props.subscriptionId) {
        payload.period_start = form.value.period_start || undefined;
        payload.status = form.value.status || undefined;
        payload.assigned_cleaner_id = form.value.assigned_cleaner_id || null;
        payload.reprice = form.value.reprice;

        if (lastPayment.value) {
            payload.payment = {
                method: form.value.payment_method || null,
                reference: form.value.payment_reference || null,
                paid_at: form.value.payment_paid_at || null,
            };
        }

        // Not sent at all when it cannot be changed, so the server has nothing
        // to refuse and the reply carries no notice about it.
        if (!isAdmin.value) delete payload.registration;
        // Create-only: the plan already has its customer, and sending an empty
        // one would look like an attempt to clear it.
        delete payload.customer_id;
    }

    if (form.value.agreed_amount !== '' && form.value.agreed_amount !== null) {
        payload.agreed_amount_paise = Math.round(Number(form.value.agreed_amount) * 100);
        payload.agreed_reason = form.value.agreed_reason;
    }

    try {
        if (props.subscriptionId) {
            await api.patch(`/subscriptions/${props.subscriptionId}`, payload);
        } else {
            await api.post('/subscriptions', payload);
        }

        await queryClient.invalidateQueries({ queryKey: ['subscriptions'] });
        emit('saved');
    } catch (e) {
        error.value = describeError(e).message;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-black/30 p-4 pt-10">
        <div class="w-full max-w-3xl rounded-lg border border-line-strong bg-surface p-4 shadow-xl">
            <h2 class="mb-3 text-lg font-semibold text-ink">
                {{ subscriptionId ? 'Edit plan' : 'New plan' }}
            </h2>

            <form class="grid gap-4 md:grid-cols-[1fr_17rem]" @submit.prevent="save">
                <div class="flex flex-col gap-3">
                    <template v-if="!subscriptionId">
                        <label class="block">
                            <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Customer</span>
                            <input
                                v-model.trim="customerSearch"
                                type="search"
                                placeholder="Search by name, phone or car number"
                                class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                            />

                            <div v-if="(customers ?? []).length && !chosenCustomer" class="mt-1 max-h-40 overflow-y-auto rounded border border-line">
                                <button
                                    v-for="c in customers"
                                    :key="c.id"
                                    type="button"
                                    class="block w-full px-3 py-1.5 text-left text-sm text-body hover:bg-sunk"
                                    @click="chosenCustomer = c; customerSearch = c.name"
                                >
                                    {{ c.name }}
                                    <span class="text-xs text-faint">{{ c.phone }} · {{ c.sector ?? 'no sector' }}</span>
                                </button>
                            </div>

                            <p v-if="chosenCustomer" class="mt-1 text-xs text-ok">
                                {{ chosenCustomer.name }} selected.
                                <button type="button" class="underline" @click="chosenCustomer = null">change</button>
                            </p>
                        </label>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="block">
                                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Car number</span>
                                <input v-model.trim="form.registration" type="text" required placeholder="UP16AB1234" class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm uppercase text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent" />
                            </label>
                            <label class="block">
                                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Car model</span>
                                <select v-model="form.vehicle_model_id" class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">
                                    <option value="">Choose…</option>
                                    <option v-for="m in catalogue?.car_models ?? []" :key="m.id" :value="m.id">{{ m.name }}</option>
                                </select>
                            </label>
                        </div>
                    </template>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Package</span>
                            <select v-model="form.package_id" required class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">
                                <option value="">Choose…</option>
                                <option v-for="p in catalogue?.packages ?? []" :key="p.id" :value="p.id">{{ p.name }}</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Interior cleaning</span>
                            <select v-model="form.service_type_id" required class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">
                                <option value="">Choose…</option>
                                <option v-for="s in catalogue?.service_types ?? []" :key="s.id" :value="s.id">{{ s.name }}</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">How long</span>
                            <select v-model="form.duration_id" required class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">
                                <option value="">Choose…</option>
                                <option v-for="d in catalogue?.durations ?? []" :key="d.id" :value="d.id">{{ d.name }}</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Cloth bundle</span>
                            <select v-model="form.cloth_bundle_id" class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">
                                <option value="">None</option>
                                <option v-for="b in catalogue?.cloth_bundles ?? []" :key="b.id" :value="b.id">{{ b.name }}</option>
                            </select>
                        </label>
                    </div>

                    <!-- Editing only: a new plan derives its own dates and starts pending. -->
                    <div v-if="subscriptionId" class="grid gap-3 sm:grid-cols-3">
                        <label class="block">
                            <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Start date</span>
                            <input v-model="form.period_start" type="date" class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm tabular-nums text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent" />
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Cleaner</span>
                            <select v-model="form.assigned_cleaner_id" class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">
                                <option value="">Not assigned</option>
                                <option v-for="c in cleaners ?? []" :key="c.id" :value="c.id">{{ c.name }}</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Status</span>
                            <select v-model="form.status" class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">
                                <option value="pending">Pending</option>
                                <option value="active">Active</option>
                                <option value="hold">On hold</option>
                                <option value="ended">Ended</option>
                            </select>
                        </label>
                    </div>

                    <!-- The car itself, editable here as v1 had it. -->
                    <div v-if="subscriptionId" class="grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Car number</span>

                            <!--
                                Administrator only. The plate is how a customer
                                is found on the phone, how the cleaner knows
                                which car is theirs, and what every past payment
                                is filed under - so correcting one is a real
                                need, but not everybody's to do. The server
                                refuses it too; this only stops the field
                                looking editable when it is not.
                            -->
                            <input
                                v-if="isAdmin"
                                v-model.trim="form.registration"
                                type="text"
                                class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm uppercase text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                            />

                            <template v-else>
                                <p class="rounded border border-line bg-sunk px-3 py-2 text-sm uppercase tabular-nums text-body">
                                    {{ form.registration || '—' }}
                                </p>
                                <span class="mt-1 block text-xs text-faint">
                                    Only an administrator can change a car number.
                                </span>
                            </template>
                        </label>
                        <label class="block">
                            <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Car model</span>
                            <select v-model="form.vehicle_model_id" class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">
                                <option value="">Choose…</option>
                                <option v-for="m in catalogue?.car_models ?? []" :key="m.id" :value="m.id">{{ m.name }}</option>
                            </select>
                        </label>
                    </div>

                    <!--
                        The payment behind this plan.

                        v1 put the mode, the date and the reference on this form
                        and the office expects them here. They are the latest
                        payment's, and saying so matters: correcting them fixes
                        the receipt too.
                    -->
                    <div v-if="subscriptionId && lastPayment" class="rounded border border-line p-3">
                        <div class="mb-2 flex flex-wrap items-baseline gap-2">
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-muted">Last payment</h3>
                            <span class="text-xs tabular-nums text-faint">
                                {{ lastPayment.invoice_number ?? 'no receipt number' }} ·
                                {{ lastPayment.order_type === 'offline' ? 'taken at the office' : 'taken online' }}
                            </span>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-3">
                            <label class="block">
                                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Payment mode</span>
                                <input v-model.trim="form.payment_method" type="text" placeholder="upi, cash, card" class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent" />
                            </label>

                            <label class="block">
                                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Payment date</span>
                                <input v-model="form.payment_paid_at" type="date" class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm tabular-nums text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent" />
                            </label>

                            <label class="block">
                                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Payment id</span>
                                <input v-model.trim="form.payment_reference" type="text" class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent" />
                            </label>
                        </div>

                        <p class="mt-2 text-xs text-faint">
                            The amount is not editable here. Returning money is a refund, which is
                            recorded on its own rather than by changing what was charged.
                        </p>
                    </div>

                    <!--
                        Re-pricing is asked for, never automatic: changing a
                        package on a running plan should not silently change what
                        the customer owes.
                    -->
                    <label v-if="subscriptionId" class="flex items-start gap-2 rounded border border-line p-3">
                        <input v-model="form.reprice" type="checkbox" class="mt-0.5 accent-[var(--accent)]" />
                        <span class="text-sm text-body">
                            Re-price from the masters
                            <span class="block text-xs text-faint">
                                Charges this plan the price shown on the right. Leave off to keep the amount as it is.
                            </span>
                        </span>
                    </label>

                    <details class="rounded border border-line p-3">
                        <summary class="cursor-pointer text-sm font-medium text-body">A different price was agreed</summary>
                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <label class="block">
                                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Agreed amount</span>
                                <input v-model="form.agreed_amount" type="number" min="0" step="1" class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm tabular-nums text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent" />
                            </label>
                            <label class="block">
                                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Why</span>
                                <input v-model.trim="form.agreed_reason" type="text" placeholder="Required" class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent" />
                            </label>
                        </div>
                        <p class="mt-2 text-xs text-faint">
                            Recorded against the plan with its reason, rather than quietly replacing the price.
                        </p>
                    </details>
                </div>

                <!-- The server's price -->
                <aside class="rounded-lg border border-line-strong p-3">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-muted">Price</h3>

                    <p v-if="!form.duration_id" class="mt-2 text-sm text-muted">
                        Choose a duration to see the price.
                    </p>

                    <template v-else-if="quote">
                        <dl class="mt-2 flex flex-col gap-1 text-sm">
                            <div v-for="(line, i) in quote.lines" :key="i" class="flex justify-between gap-2">
                                <dt class="text-body">{{ line.label }}</dt>
                                <dd class="tabular-nums" :class="line.amount < 0 ? 'text-ok' : 'text-ink'">
                                    {{ line.amount < 0 ? '−' : '' }}{{ money(Math.abs(line.amount)) }}
                                </dd>
                            </div>
                        </dl>

                        <div class="mt-2 flex justify-between border-t border-line pt-2">
                            <span class="font-semibold text-ink">Total</span>
                            <span class="font-bold tabular-nums text-ink">{{ quote.formatted }}</span>
                        </div>

                        <p v-for="w in quote.warnings" :key="w" class="mt-2 rounded bg-warn-soft px-2 py-1 text-xs text-warn">
                            {{ w }}
                        </p>

                        <span v-if="pricing" class="mt-1 block text-xs text-faint">updating…</span>
                    </template>

                    <p class="mt-3 text-xs text-faint">
                        Worked out by the server, and worked out again when you save.
                    </p>

                    <p v-if="error" class="mt-2 rounded bg-crit-soft px-2 py-1.5 text-xs text-crit">{{ error }}</p>

                    <button
                        type="submit"
                        :disabled="saving || (!subscriptionId && !form.customer_id)"
                        class="mt-3 w-full rounded bg-accent px-4 py-2 text-sm font-semibold text-on-accent transition hover:brightness-110 disabled:opacity-50"
                    >
                        {{ saving ? 'Saving…' : subscriptionId ? 'Save changes' : 'Create plan' }}
                    </button>

                    <button type="button" class="mt-2 w-full rounded border border-line-strong px-4 py-2 text-sm text-body hover:bg-sunk" @click="emit('close')">
                        Cancel
                    </button>

                    <p v-if="!subscriptionId" class="mt-2 text-xs text-faint">
                        Created as pending. It goes active when a payment lands.
                    </p>
                </aside>
            </form>
        </div>
    </div>
</template>
