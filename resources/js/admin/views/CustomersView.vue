<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useQuery, useQueryClient, keepPreviousData } from '@tanstack/vue-query';
import { api, describeError } from '@/shared/api/client';
import { useAuthStore } from '@/shared/stores/auth';
import SortableHeader from '@/admin/components/SortableHeader.vue';

interface CustomerRow {
    id: string;
    name: string;
    phone: string | null;
    email: string | null;
    house_no: string | null;
    sector: string | null;
    sector_id: string | null;
    society: string | null;
    society_id: string | null;
    status: boolean;
    vehicles_count: number | null;
    active_subscriptions_count: number | null;
}

const auth = useAuthStore();
const queryClient = useQueryClient();

const search = ref('');
const activeOnly = ref(false);
const page = ref(1);
const sort = ref('name');
const direction = ref<'asc' | 'desc'>('asc');

const editing = ref<CustomerRow | null>(null);
const detail = ref<string | null>(null);
const form = ref<Record<string, unknown>>({});
const formError = ref<string | null>(null);
const saving = ref(false);

watch([search, activeOnly, sort, direction], () => { page.value = 1; });

const { data, isPending, isError, error, isFetching } = useQuery({
    queryKey: computed(() => [
        'customers', search.value, activeOnly.value, page.value, sort.value, direction.value, auth.selectedSectorId,
    ]),
    placeholderData: keepPreviousData,
    queryFn: async () => (await api.get('/customers', {
        params: {
            page: page.value,
            search: search.value || undefined,
            // The picker in the top bar.
            sector_id: auth.selectedSectorId || undefined,
            with_active: activeOnly.value ? 1 : undefined,
            sort: sort.value,
            direction: direction.value,
        },
    })).data,
});

const rows = computed<CustomerRow[]>(() => data.value?.data ?? []);
const meta = computed(() => data.value?.meta);

/** The one customer being looked at in full, with their cars. */
const { data: detailData } = useQuery({
    queryKey: computed(() => ['customer', detail.value]),
    enabled: computed(() => detail.value !== null),
    queryFn: async () => (await api.get(`/customers/${detail.value}`)).data.data,
});

/**
 * One level of the address at a time, the same cascade as the public form -
 * and now literally the same endpoint.
 *
 * It used to read /masters, which is administrator-only: every level 403'd for
 * a franchise owner, so the dropdowns rendered empty and they could not set a
 * customer's address at all. /public/locations answers the same question for
 * anybody, and already refuses to offer a sector nobody covers.
 */
function useLevel(level: string, parent: () => string) {
    return useQuery({
        queryKey: computed(() => ['locations', level, parent()]),
        enabled: computed(() => level === 'states' || !!parent()),
        queryFn: async () => (await api.get('/public/locations', {
            params: { level, parent_id: parent() || undefined },
        })).data.data,
        staleTime: 5 * 60 * 1000,
    });
}

const { data: states } = useLevel('states', () => '');
const { data: cities } = useLevel('cities', () => String(form.value.state_id ?? ''));
const { data: areas } = useLevel('areas', () => String(form.value.city_id ?? ''));

/*
 * Choosing a state invalidates everything under it, so a record can never be
 * saved with a city in one state and a sector in another.
 *
 * Skipped while a customer is being loaded: filling state, city and area in
 * sequence would otherwise clear the sector that was just set.
 */
watch(() => form.value.state_id, () => {
    if (loadingForm.value) return;
    form.value.city_id = ''; form.value.area_id = ''; form.value.sector_id = ''; form.value.society_id = '';
});
watch(() => form.value.city_id, () => {
    if (loadingForm.value) return;
    form.value.area_id = ''; form.value.sector_id = ''; form.value.society_id = '';
});
watch(() => form.value.area_id, () => {
    if (loadingForm.value) return;
    form.value.sector_id = ''; form.value.society_id = '';
});

const { data: sectors } = useQuery({
    queryKey: computed(() => ['masters', 'sectors', form.value.area_id]),
    queryFn: async () => (await api.get('/public/locations', {
        params: { level: 'sectors', parent_id: form.value.area_id },
    })).data.data,
    staleTime: 5 * 60 * 1000,
});

const { data: societies } = useQuery({
    queryKey: computed(() => ['masters', 'societies', form.value.sector_id]),
    enabled: computed(() => !!form.value.sector_id),
    queryFn: async () => (await api.get('/public/locations', {
        params: { level: 'societies', parent_id: form.value.sector_id },
    })).data.data,
});

function onSort(field: string, next: 'asc' | 'desc') {
    sort.value = field;
    direction.value = next;
}

// ------------------------------------------------------------------- cars

const addingCar = ref(false);
const savingCar = ref(false);
const carError = ref<string | null>(null);
const newCar = ref({ registration: '', vehicle_model_id: '' });

const { data: carModels } = useQuery({
    // The public price list, not /masters, for the same reason as the address
    // cascade above: /masters is administrator-only.
    queryKey: ['catalogue', 'car-models'],
    queryFn: async () => (await api.get('/public/catalogue')).data.data.car_models,
    staleTime: 5 * 60 * 1000,
});

async function addVehicle() {
    if (!detail.value) return;

    savingCar.value = true;
    carError.value = null;

    try {
        await api.post(`/customers/${detail.value}/vehicles`, {
            registration: newCar.value.registration,
            vehicle_model_id: newCar.value.vehicle_model_id || null,
        });

        newCar.value = { registration: '', vehicle_model_id: '' };
        addingCar.value = false;

        // Both lists move: the panel gains a car and the table's count changes.
        await queryClient.invalidateQueries({ queryKey: ['customer', detail.value] });
        await queryClient.invalidateQueries({ queryKey: ['customers'] });
    } catch (e) {
        carError.value = describeError(e).message;
    } finally {
        savingCar.value = false;
    }
}

async function removeVehicle(id: string, registration: string) {
    if (!detail.value) return;
    if (!confirm(`Remove ${registration}? Its service history is kept.`)) return;

    try {
        await api.delete(`/customers/${detail.value}/vehicles/${id}`);
        await queryClient.invalidateQueries({ queryKey: ['customer', detail.value] });
        await queryClient.invalidateQueries({ queryKey: ['customers'] });
    } catch (e) {
        // Refused while a plan is running against it, which is the message
        // worth showing rather than a generic failure.
        alert(describeError(e).message);
    }
}

function startNew() {
    formError.value = null;
    editing.value = { id: '' } as CustomerRow;
    form.value = { name: '', phone: '', email: '', state_id: '', city_id: '', area_id: '', sector_id: '', society_id: '', house_no: '', address: '', preferred_time: '09:00', status: true };
}

/**
 * Open a customer for editing, with the whole address filled in.
 *
 * The full record is fetched first: the list carries a sector but not the state
 * and city above it, so opening the form from row data alone left three empty
 * selects on a customer whose address was perfectly good.
 *
 * `loadingForm` guards the cascade - the watchers below clear each level when
 * its parent changes, and setting state, city and area in sequence would
 * otherwise wipe the sector on the way down.
 */
const loadingForm = ref(false);

async function startEdit(row: CustomerRow) {
    formError.value = null;
    editing.value = row;
    loadingForm.value = true;

    // Shown immediately from the row so the dialog is never blank while the
    // rest arrives.
    form.value = {
        name: row.name, phone: row.phone ?? '', email: row.email ?? '',
        state_id: '', city_id: '', area_id: '',
        sector_id: row.sector_id ?? '', society_id: row.society_id ?? '',
        house_no: row.house_no ?? '', status: row.status,
    };

    try {
        const { data } = await api.get(`/customers/${row.id}`);
        const full = data.data;

        form.value = {
            name: full.name,
            phone: full.phone ?? '',
            email: full.email ?? '',
            state_id: full.state_id ?? '',
            city_id: full.city_id ?? '',
            area_id: full.area_id ?? '',
            sector_id: full.sector_id ?? '',
            society_id: full.society_id ?? '',
            house_no: full.house_no ?? '',
            address: full.address ?? '',
            preferred_time: full.preferred_time ?? '',
            status: full.status,
        };
    } catch {
        // The sector is already filled in from the row, so the form still
        // works - it just cannot show the levels above it.
        formError.value = 'The full address could not be loaded. Everything else is editable.';
    } finally {
        // Released on the next tick so the watchers see the finished form
        // rather than each field landing one at a time.
        setTimeout(() => { loadingForm.value = false; }, 0);
    }
}

async function save() {
    if (!editing.value) return;

    saving.value = true;
    formError.value = null;

    try {
        if (editing.value.id) {
            await api.patch(`/customers/${editing.value.id}`, form.value);
        } else {
            await api.post('/customers', form.value);
        }
        editing.value = null;
        await queryClient.invalidateQueries({ queryKey: ['customers'] });
    } catch (e) {
        formError.value = describeError(e).message;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div>
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <h1 class="text-xl font-semibold tracking-tight text-ink">Customers</h1>
            <span v-if="isFetching" class="text-xs text-faint">updating…</span>
            <span v-if="meta" class="text-sm text-muted">{{ meta.total }} on the books</span>

            <button
                v-if="auth.can('create.customer')"
                type="button"
                class="ms-auto rounded bg-accent px-3 py-1.5 text-sm font-medium text-on-accent transition hover:brightness-110 focus:outline-none focus:ring-2 focus:ring-accent"
                @click="startNew"
            >
                Add customer
            </button>
        </div>

        <div class="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-line bg-surface p-3">
            <label>
                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Search</span>
                <input
                    v-model.trim="search"
                    type="search"
                    placeholder="Name, phone, email or car number"
                    class="w-full rounded border border-line-strong bg-surface px-3 py-1.5 text-sm text-ink sm:w-80 focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                />
            </label>

            <label class="flex items-center gap-2 pb-1.5 text-sm text-body">
                <input v-model="activeOnly" type="checkbox" class="rounded border-line-strong" />
                Paying right now
            </label>
        </div>

        <p v-if="isError" class="rounded border border-crit bg-crit-soft px-3 py-2 text-sm text-crit">
            {{ describeError(error).message }}
        </p>

        <div v-else class="overflow-x-auto rounded-lg border border-line bg-surface">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-line text-xs text-muted">
                        <SortableHeader field="name" :sort="meta?.sort ?? sort" :direction="meta?.direction ?? direction" @sort="onSort">Name</SortableHeader>
                        <SortableHeader field="phone" :sort="meta?.sort ?? sort" :direction="meta?.direction ?? direction" @sort="onSort">Contact</SortableHeader>
                        <SortableHeader field="sector" :sort="meta?.sort ?? sort" :direction="meta?.direction ?? direction" @sort="onSort">Where</SortableHeader>
                        <SortableHeader field="vehicles" align="right" :sort="meta?.sort ?? sort" :direction="meta?.direction ?? direction" @sort="onSort">Cars</SortableHeader>
                        <SortableHeader field="active" align="right" :sort="meta?.sort ?? sort" :direction="meta?.direction ?? direction" @sort="onSort">Active plans</SortableHeader>
                        <th class="px-3 py-2 text-right font-medium uppercase tracking-wide">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="isPending">
                        <td colspan="6" class="px-3 py-6 text-center text-muted">Loading…</td>
                    </tr>
                    <tr v-else-if="!rows.length">
                        <td colspan="6" class="px-3 py-6 text-center text-muted">Nobody matches those filters.</td>
                    </tr>
                    <tr
                        v-for="row in rows"
                        :key="row.id"
                        class="border-b border-line last:border-0 hover:bg-sunk"
                    >
                        <td class="px-3 py-2 font-medium text-ink">
                            <button type="button" class="hover:text-accent-ink" @click="detail = row.id">
                                {{ row.name }}
                            </button>
                        </td>
                        <td class="px-3 py-2 text-body">
                            <span class="tabular-nums">{{ row.phone ?? '—' }}</span>
                            <div class="text-xs text-faint">{{ row.email }}</div>
                        </td>
                        <td class="px-3 py-2 text-body">
                            {{ row.sector ?? '—' }}
                            <div class="text-xs text-faint">{{ row.society }} {{ row.house_no }}</div>
                        </td>
                        <td class="px-3 py-2 text-right tabular-nums text-body">{{ row.vehicles_count ?? 0 }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">
                            <span
                                class="rounded px-2 py-0.5 text-xs font-medium"
                                :class="(row.active_subscriptions_count ?? 0) > 0 ? 'bg-ok-soft text-ok' : 'bg-sunk text-muted'"
                            >
                                {{ row.active_subscriptions_count ?? 0 }}
                            </span>
                        </td>
                        <td class="px-3 py-2 text-right whitespace-nowrap">
                            <button type="button" class="rounded px-2 py-1 text-xs font-medium text-accent-ink hover:bg-accent-soft" @click="detail = row.id">
                                View
                            </button>
                            <button
                                v-if="auth.can('update.customer')"
                                type="button"
                                class="rounded px-2 py-1 text-xs font-medium text-body hover:bg-sunk"
                                @click="startEdit(row)"
                            >
                                Edit
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="meta && meta.last_page > 1" class="mt-3 flex items-center gap-3">
            <button type="button" class="rounded border border-line-strong px-3 py-1.5 text-sm disabled:opacity-50" :disabled="page <= 1" @click="page--">Previous</button>
            <span class="text-sm tabular-nums text-body">Page {{ meta.current_page }} of {{ meta.last_page }}</span>
            <button type="button" class="rounded border border-line-strong px-3 py-1.5 text-sm disabled:opacity-50" :disabled="page >= meta.last_page" @click="page++">Next</button>
        </div>

        <!-- One customer, with their cars -->
        <div v-if="detail" class="fixed inset-0 z-40 flex items-start justify-center bg-black/30 p-4 pt-16" @click.self="detail = null">
            <div class="w-full max-w-lg rounded-lg border border-line-strong bg-surface p-4 shadow-xl">
                <div class="mb-3 flex items-start gap-3">
                    <h2 class="text-lg font-semibold text-ink">{{ detailData?.name ?? 'Loading…' }}</h2>
                    <button type="button" class="ms-auto text-sm text-muted hover:text-ink" @click="detail = null">Close</button>
                </div>

                <template v-if="detailData">
                    <dl class="grid grid-cols-3 gap-y-1.5 text-sm">
                        <dt class="text-muted">Phone</dt>
                        <dd class="col-span-2 tabular-nums text-ink">{{ detailData.phone ?? '—' }}</dd>
                        <dt class="text-muted">Email</dt>
                        <dd class="col-span-2 text-ink">{{ detailData.email ?? '—' }}</dd>
                        <dt class="text-muted">Address</dt>
                        <dd class="col-span-2 text-ink">
                            {{ [detailData.house_no, detailData.society, detailData.sector].filter(Boolean).join(', ') || '—' }}
                        </dd>
                        <dt class="text-muted">Preferred</dt>
                        <dd class="col-span-2 tabular-nums text-ink">{{ detailData.preferred_time ?? '—' }}</dd>
                    </dl>

                    <div class="mt-4 mb-2 flex items-center gap-2">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-muted">Cars</h3>
                        <button
                            v-if="auth.can('create.vehicle')"
                            type="button"
                            class="ms-auto rounded border border-line-strong px-2 py-1 text-xs text-body transition hover:bg-sunk"
                            @click="addingCar = true"
                        >
                            Add a car
                        </button>
                    </div>

                    <!-- Adding a car is two fields: the number, and who cleans it. -->
                    <form v-if="addingCar" class="mb-2 rounded border border-line-strong bg-sunk p-3" @submit.prevent="addVehicle">
                        <div class="grid gap-2 sm:grid-cols-2">
                            <input
                                v-model.trim="newCar.registration"
                                type="text" required placeholder="UP16AB1234"
                                class="rounded border border-line-strong bg-surface px-3 py-2 text-sm uppercase text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                            />
                            <select
                                v-model="newCar.vehicle_model_id"
                                class="rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                            >
                                <option value="">Car model…</option>
                                <option v-for="m in carModels ?? []" :key="m.id" :value="m.id">{{ m.name }}</option>
                            </select>
                        </div>

                        <p v-if="carError" class="mt-2 rounded bg-crit-soft px-2 py-1 text-xs text-crit">{{ carError }}</p>

                        <div class="mt-2 flex gap-2">
                            <button type="submit" :disabled="savingCar" class="rounded bg-accent px-3 py-1.5 text-xs font-medium text-on-accent transition hover:brightness-110 disabled:opacity-60">
                                {{ savingCar ? 'Adding…' : 'Add' }}
                            </button>
                            <button type="button" class="rounded border border-line-strong px-3 py-1.5 text-xs text-body hover:bg-surface" @click="addingCar = false">
                                Cancel
                            </button>
                        </div>
                    </form>

                    <p v-if="!detailData.vehicles.length && !addingCar" class="text-sm text-muted">No cars on record.</p>
                    <ul v-else class="flex flex-col gap-2">
                        <li v-for="v in detailData.vehicles" :key="v.id" class="rounded border border-line p-3">
                            <div class="flex items-baseline gap-2">
                                <span class="font-medium text-ink">{{ v.registration }}</span>
                                <span v-if="v.subscription" class="text-xs text-muted">
                                    renews {{ v.subscription.period_end ?? '—' }}
                                </span>
                                <span
                                    v-if="v.subscription"
                                    class="ms-auto rounded px-2 py-0.5 text-xs font-medium"
                                    :class="v.subscription.status === 'active' ? 'bg-ok-soft text-ok' : 'bg-warn-soft text-warn'"
                                >
                                    {{ v.subscription.status }}
                                </span>
                            </div>
                            <p class="mt-1 text-xs" :class="v.cleaner ? 'text-muted' : 'text-warn'">
                                {{ v.cleaner ? 'Cleaned by ' + v.cleaner : 'No cleaner assigned' }}
                                <button
                                    v-if="auth.can('update.vehicle')"
                                    type="button"
                                    class="ms-2 text-crit hover:underline"
                                    @click="removeVehicle(v.id, v.registration)"
                                >
                                    remove
                                </button>
                            </p>
                        </li>
                    </ul>
                </template>
            </div>
        </div>

        <!-- Add / edit -->
        <div v-if="editing" class="fixed inset-0 z-40 flex items-start justify-center bg-black/30 p-4 pt-16">
            <div class="w-full max-w-lg rounded-lg border border-line-strong bg-surface p-4 shadow-xl">
                <h2 class="mb-3 text-lg font-semibold text-ink">{{ editing.id ? 'Edit customer' : 'Add customer' }}</h2>

                <form class="flex flex-col gap-3" @submit.prevent="save">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label>
                            <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Name</span>
                            <input v-model.trim="form.name" type="text" required class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent" />
                        </label>
                        <label>
                            <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Phone</span>
                            <input v-model.trim="form.phone" type="tel" required class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm tabular-nums text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent" />
                        </label>
                    </div>

                    <label>
                        <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Email</span>
                        <input v-model.trim="form.email" type="email" class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent" />
                    </label>

                    <!--
                        The whole address, narrowing one level at a time, the
                        same as the public form. Left blank when editing, since
                        the list does not carry them - choosing a state starts
                        the cascade and replaces the sector below.
                    -->
                    <div class="grid gap-3 sm:grid-cols-3">
                        <label>
                            <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">State</span>
                            <select v-model="form.state_id" class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">
                                <option value="">Choose…</option>
                                <option v-for="o in states ?? []" :key="o.id" :value="o.id">{{ o.name }}</option>
                            </select>
                        </label>
                        <label>
                            <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">City</span>
                            <select v-model="form.city_id" :disabled="!form.state_id" class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink disabled:opacity-50 focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">
                                <option value="">Choose…</option>
                                <option v-for="o in cities ?? []" :key="o.id" :value="o.id">{{ o.name }}</option>
                            </select>
                        </label>
                        <label>
                            <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Area</span>
                            <select v-model="form.area_id" :disabled="!form.city_id" class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink disabled:opacity-50 focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">
                                <option value="">Choose…</option>
                                <option v-for="o in areas ?? []" :key="o.id" :value="o.id">{{ o.name }}</option>
                            </select>
                        </label>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <label>
                            <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Sector</span>
                            <select v-model="form.sector_id" required class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">
                                <option value="">Choose…</option>
                                <option v-for="s in sectors ?? []" :key="s.id" :value="s.id">{{ s.name }}</option>
                            </select>
                            <span class="mt-1 block text-xs text-faint">The branch follows the sector.</span>
                        </label>
                        <label>
                            <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Society</span>
                            <select v-model="form.society_id" :disabled="!form.sector_id" class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink disabled:opacity-50 focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">
                                <option value="">Choose…</option>
                                <option v-for="s in societies ?? []" :key="s.id" :value="s.id">{{ s.name }}</option>
                            </select>
                        </label>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <label>
                            <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Flat / house no.</span>
                            <input v-model.trim="form.house_no" type="text" class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent" />
                        </label>
                        <label v-if="!editing.id">
                            <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Preferred time</span>
                            <input v-model="form.preferred_time" type="time" class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm tabular-nums text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent" />
                        </label>
                    </div>

                    <p v-if="formError" class="rounded bg-crit-soft px-3 py-2 text-sm text-crit">{{ formError }}</p>

                    <div class="mt-1 flex gap-2">
                        <button type="submit" :disabled="saving" class="rounded bg-accent px-4 py-2 text-sm font-medium text-on-accent transition hover:brightness-110 disabled:opacity-60">
                            {{ saving ? 'Saving…' : 'Save' }}
                        </button>
                        <button type="button" class="rounded border border-line-strong px-4 py-2 text-sm text-body transition hover:bg-sunk" @click="editing = null">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
