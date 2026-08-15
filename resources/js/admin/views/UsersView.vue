<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useQuery, useQueryClient, keepPreviousData } from '@tanstack/vue-query';
import { api, describeError } from '@/shared/api/client';
import { useAuthStore } from '@/shared/stores/auth';
import { assignRole, fetchRoles } from '@/admin/shared/roles.api';

interface UserRow {
    id: string;
    name: string;
    email: string | null;
    phone: string | null;
    role: { value: string; label: string };
    /** The role the business defined, if this account has been given one. */
    custom_role: { id: string; name: string; status: boolean } | null;
    custom_role_id: string | null;
    branch: { id: string; name: string } | null;
    status: boolean;
    removed: boolean;
}

const auth = useAuthStore();
const queryClient = useQueryClient();

/**
 * Custom roles, for the picker on each row.
 *
 * Only an administrator may read or apply these, so the query is switched off
 * for everybody else rather than firing and failing with a 403.
 */
const isAdmin = computed(() => auth.user?.role.value === 'super_admin');

const { data: customRoles } = useQuery({
    queryKey: ['roles'],
    enabled: isAdmin,
    queryFn: fetchRoles,
    staleTime: 5 * 60 * 1000,
});

const roleNotice = ref<string | null>(null);

async function applyRole(userId: string, event: Event) {
    const roleId = (event.target as HTMLSelectElement).value;

    roleNotice.value = null;

    try {
        roleNotice.value = await assignRole(userId, roleId || null);
        await queryClient.invalidateQueries({ queryKey: ['users'] });
    } catch (e) {
        roleNotice.value = describeError(e).message;
    }
}

const search = ref('');
const roleFilter = ref('');
const includeRemoved = ref(false);
const page = ref(1);

const editing = ref<UserRow | null>(null);
const form = ref({ name: '', email: '', phone: '', role: '', branch_id: '', password: '', status: true });
const formError = ref<string | null>(null);
const saving = ref(false);

watch([search, roleFilter, includeRemoved], () => { page.value = 1; });

const { data, isPending, isError, error, isFetching } = useQuery({
    queryKey: computed(() => ['users', search.value, roleFilter.value, includeRemoved.value, page.value, auth.selectedBranchId]),
    placeholderData: keepPreviousData,
    queryFn: async () => (await api.get('/users', {
        params: {
            page: page.value,
            search: search.value || undefined,
            role: roleFilter.value || undefined,
            include_disabled: includeRemoved.value ? 1 : undefined,
        },
    })).data,
});

const rows = computed<UserRow[]>(() => data.value?.data ?? []);
const meta = computed(() => data.value?.meta);

/**
 * Only the roles this person may hand out. The list comes from the server,
 * which decides the same thing again on save - the form is a convenience, not
 * the rule.
 */
const assignableRoles = computed(() => meta.value?.assignable_roles ?? []);

function startNew() {
    formError.value = null;
    editing.value = { id: '' } as UserRow;
    form.value = {
        name: '', email: '', phone: '',
        role: assignableRoles.value[0]?.value ?? '',
        branch_id: auth.user?.sees_all_branches ? '' : (auth.user?.branch?.id ?? ''),
        password: '', status: true,
    };
}

function startEdit(row: UserRow) {
    formError.value = null;
    editing.value = row;
    form.value = {
        name: row.name,
        email: row.email ?? '',
        phone: row.phone ?? '',
        role: row.role.value,
        branch_id: row.branch?.id ?? '',
        // Left blank on purpose: an empty box means "leave it as it was".
        password: '',
        status: row.status,
    };
}

async function save() {
    if (!editing.value) return;

    saving.value = true;
    formError.value = null;

    const payload: Record<string, unknown> = {
        name: form.value.name,
        email: form.value.email || null,
        phone: form.value.phone || null,
        role: form.value.role,
        status: form.value.status,
    };

    if (auth.user?.sees_all_branches) payload.branch_id = form.value.branch_id || null;
    if (form.value.password) payload.password = form.value.password;

    try {
        if (editing.value.id) {
            await api.patch(`/users/${editing.value.id}`, payload);
        } else {
            await api.post('/users', payload);
        }
        editing.value = null;
        await queryClient.invalidateQueries({ queryKey: ['users'] });
    } catch (e) {
        formError.value = describeError(e).message;
    } finally {
        saving.value = false;
    }
}

async function removeAccess(row: UserRow) {
    if (!confirm(`Remove ${row.name}'s access? Their record of past work is kept.`)) return;

    try {
        await api.delete(`/users/${row.id}`);
        await queryClient.invalidateQueries({ queryKey: ['users'] });
    } catch (e) {
        alert(describeError(e).message);
    }
}

async function restore(row: UserRow) {
    await api.post(`/users/${row.id}/restore`);
    await queryClient.invalidateQueries({ queryKey: ['users'] });
}

const roleFilters = [
    { value: '', label: 'Everyone' },
    { value: 'super_admin', label: 'Administrators' },
    { value: 'franchise_owner', label: 'Franchise owners' },
    { value: 'cleaner', label: 'Cleaners' },
    { value: 'customer', label: 'Customers' },
];
</script>

<template>
    <div>
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <h1 class="text-xl font-semibold tracking-tight text-ink">People</h1>
            <span v-if="isFetching" class="text-xs text-faint">updating…</span>
            <span v-if="roleNotice" class="text-xs text-ok" role="status">{{ roleNotice }}</span>

            <button
                v-if="assignableRoles.length"
                type="button"
                class="ms-auto rounded bg-accent px-3 py-1.5 text-sm font-medium text-on-accent transition hover:brightness-110 focus:outline-none focus:ring-2 focus:ring-accent"
                @click="startNew"
            >
                Add someone
            </button>
        </div>

        <div class="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-line bg-surface p-3">
            <label>
                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Search</span>
                <input
                    v-model.trim="search"
                    type="search"
                    placeholder="Name, email or phone"
                    class="w-full rounded border border-line-strong bg-surface px-3 py-1.5 text-sm text-ink sm:w-64 focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                />
            </label>

            <label>
                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Role</span>
                <select
                    v-model="roleFilter"
                    class="rounded border border-line-strong bg-surface px-2 py-1.5 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                >
                    <option v-for="r in roleFilters" :key="r.value" :value="r.value">{{ r.label }}</option>
                </select>
            </label>

            <label class="flex items-center gap-2 pb-1.5 text-sm text-body">
                <input v-model="includeRemoved" type="checkbox" class="rounded border-line-strong" />
                Show removed
            </label>
        </div>

        <p v-if="isError" class="rounded border border-crit bg-crit-soft px-3 py-2 text-sm text-crit">
            {{ describeError(error).message }}
        </p>

        <div v-else class="overflow-x-auto rounded-lg border border-line bg-surface">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-line text-left text-xs uppercase tracking-wide text-muted">
                        <th class="px-3 py-2 font-medium">Name</th>
                        <th class="px-3 py-2 font-medium">Contact</th>
                        <th class="px-3 py-2 font-medium">Role</th>
                        <th class="px-3 py-2 font-medium">Branch</th>
                        <th class="px-3 py-2 font-medium">Status</th>
                        <th class="px-3 py-2 text-right font-medium">Actions</th>
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
                        :class="row.removed ? 'opacity-60' : ''"
                    >
                        <td class="px-3 py-2 font-medium text-ink">{{ row.name }}</td>
                        <td class="px-3 py-2 text-body">
                            {{ row.email ?? '—' }}
                            <div class="text-xs text-faint tabular-nums">{{ row.phone }}</div>
                        </td>
                        <td class="px-3 py-2 text-body">
                            {{ row.role.label }}

                            <!--
                                The built-in role stays visible above the custom
                                one: it is what decides which branch somebody
                                sees, and hiding it would leave the screen
                                unable to explain what they can look at.
                            -->
                            <select
                                v-if="isAdmin && row.role.value !== 'super_admin' && !row.removed"
                                :value="row.custom_role_id ?? ''"
                                class="mt-1 block w-full rounded border border-line bg-surface px-1.5 py-1 text-xs text-body focus:border-accent focus:outline-none"
                                @change="applyRole(row.id, $event)"
                            >
                                <option value="">Built-in permissions</option>
                                <option
                                    v-for="r in (customRoles ?? []).filter((cr) => cr.base_role === row.role.value)"
                                    :key="r.id"
                                    :value="r.id"
                                >
                                    {{ r.name }}{{ r.status ? '' : ' (off)' }}
                                </option>
                            </select>
                        </td>
                        <td class="px-3 py-2 text-body">{{ row.branch?.name ?? 'All branches' }}</td>
                        <td class="px-3 py-2">
                            <span
                                class="rounded px-2 py-0.5 text-xs font-medium"
                                :class="row.removed ? 'bg-crit-soft text-crit'
                                    : row.status ? 'bg-ok-soft text-ok' : 'bg-warn-soft text-warn'"
                            >
                                {{ row.removed ? 'Removed' : row.status ? 'Active' : 'Suspended' }}
                            </span>
                        </td>
                        <td class="px-3 py-2 text-right whitespace-nowrap">
                            <button
                                v-if="!row.removed"
                                type="button"
                                class="rounded px-2 py-1 text-xs font-medium text-accent-ink hover:bg-accent-soft"
                                @click="startEdit(row)"
                            >
                                Edit
                            </button>
                            <button
                                v-if="!row.removed && row.id !== auth.user?.id"
                                type="button"
                                class="rounded px-2 py-1 text-xs font-medium text-crit hover:bg-crit-soft"
                                @click="removeAccess(row)"
                            >
                                Remove
                            </button>
                            <button
                                v-if="row.removed"
                                type="button"
                                class="rounded px-2 py-1 text-xs font-medium text-ok hover:bg-ok-soft"
                                @click="restore(row)"
                            >
                                Restore
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="meta && meta.last_page > 1" class="mt-3 flex items-center gap-3">
            <button type="button" class="rounded border border-line-strong px-3 py-1.5 text-sm disabled:opacity-50" :disabled="page <= 1" @click="page--">Previous</button>
            <span class="text-sm tabular-nums text-body">Page {{ meta.current_page }} of {{ meta.last_page }} · {{ meta.total }} people</span>
            <button type="button" class="rounded border border-line-strong px-3 py-1.5 text-sm disabled:opacity-50" :disabled="page >= meta.last_page" @click="page++">Next</button>
        </div>

        <!-- Add / edit -->
        <div v-if="editing" class="fixed inset-0 z-40 flex items-start justify-center bg-black/30 p-4 pt-16">
            <div class="w-full max-w-md rounded-lg border border-line-strong bg-surface p-4 shadow-xl">
                <h2 class="mb-3 text-lg font-semibold text-ink">{{ editing.id ? 'Edit person' : 'Add someone' }}</h2>

                <form class="flex flex-col gap-3" @submit.prevent="save">
                    <label>
                        <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Name</span>
                        <input v-model.trim="form.name" type="text" required class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent" />
                    </label>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <label>
                            <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Email</span>
                            <input v-model.trim="form.email" type="email" class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent" />
                        </label>
                        <label>
                            <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Phone</span>
                            <input v-model.trim="form.phone" type="tel" class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm tabular-nums text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent" />
                        </label>
                    </div>
                    <p class="-mt-1 text-xs text-faint">One of the two is needed to sign in.</p>

                    <label>
                        <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Role</span>
                        <select
                            v-model="form.role"
                            required
                            :disabled="editing.id === auth.user?.id"
                            class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink disabled:opacity-50 focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                        >
                            <option v-for="r in assignableRoles" :key="r.value" :value="r.value">{{ r.label }}</option>
                        </select>
                        <span v-if="editing.id === auth.user?.id" class="mt-1 block text-xs text-faint">
                            You cannot change your own role. Ask another administrator.
                        </span>
                    </label>

                    <label v-if="auth.user?.sees_all_branches && form.role !== 'super_admin'">
                        <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Branch</span>
                        <select v-model="form.branch_id" class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">
                            <option value="">Choose…</option>
                            <option v-for="b in auth.branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                        </select>
                    </label>

                    <label>
                        <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">
                            Password {{ editing.id ? '(leave blank to keep)' : '' }}
                        </span>
                        <input
                            v-model="form.password"
                            type="password"
                            :required="!editing.id"
                            autocomplete="new-password"
                            minlength="8"
                            class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                        />
                    </label>

                    <label class="flex items-center gap-2 text-sm text-body">
                        <input v-model="form.status" type="checkbox" class="rounded border-line-strong" />
                        Can sign in
                    </label>

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
