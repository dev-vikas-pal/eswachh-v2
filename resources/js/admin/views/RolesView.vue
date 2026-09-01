<script setup lang="ts">
import { computed, ref } from 'vue';
import { useQuery, useQueryClient } from '@tanstack/vue-query';
import { describeError } from '@/shared/api/client';
import { refreshAfter } from '@/shared/api/refresh';
import {
    deleteRole, fetchCatalogue, fetchRoles, saveRole,
    type CustomRole, type RoleInput,
} from '@/admin/shared/roles.api';

/**
 * Roles the business defines for itself - a Supervisor, a billing clerk.
 *
 * A role refines one of the built-in roles rather than replacing it. That is
 * stated on the screen rather than left to be worked out, because the thing
 * people expect a permissions screen to control and it deliberately does not
 * is which branch somebody sees.
 */
const queryClient = useQueryClient();

const { data: catalogue, isPending: loadingCatalogue } = useQuery({
    queryKey: ['roles', 'catalogue'],
    queryFn: fetchCatalogue,
    staleTime: 10 * 60 * 1000,
});

const { data: roles, isPending, error } = useQuery({
    queryKey: ['roles'],
    queryFn: fetchRoles,
});

const editing = ref<CustomRole | null>(null);
const creating = ref(false);
const busy = ref(false);
const notice = ref<string | null>(null);
const problem = ref<string | null>(null);

const form = ref<RoleInput>({
    name: '',
    description: '',
    base_role: 'franchise_owner',
    abilities: [],
    status: true,
});

const modules = computed(() => catalogue.value?.data ?? []);
const baseRoles = computed(() => catalogue.value?.base_roles ?? []);

/** What the chosen built-in role grants, for the "start from this" button. */
const baseAbilities = computed(
    () => baseRoles.value.find((r) => r.value === form.value.base_role)?.abilities ?? [],
);

function open(role: CustomRole | null) {
    problem.value = null;
    notice.value = null;
    editing.value = role;
    creating.value = role === null;

    form.value = role
        ? {
            name: role.name,
            description: role.description ?? '',
            base_role: role.base_role,
            abilities: [...role.abilities],
            status: role.status,
        }
        : { name: '', description: '', base_role: 'franchise_owner', abilities: [], status: true };
}

function close() {
    editing.value = null;
    creating.value = false;
}

function toggle(ability: string) {
    const at = form.value.abilities.indexOf(ability);

    if (at === -1) form.value.abilities.push(ability);
    else form.value.abilities.splice(at, 1);
}

/** Tick or clear a whole module at once - the common way people build a role. */
function toggleModule(keys: string[]) {
    const allOn = keys.every((k) => form.value.abilities.includes(k));

    form.value.abilities = allOn
        ? form.value.abilities.filter((a) => !keys.includes(a))
        : [...new Set([...form.value.abilities, ...keys])];
}

async function save() {
    busy.value = true;
    problem.value = null;

    try {
        await saveRole(
            { ...form.value, description: form.value.description || null },
            editing.value?.id,
        );

        await refreshAfter(queryClient, 'roles');
        notice.value = 'Saved.';
        close();
    } catch (e) {
        problem.value = describeError(e).message;
    } finally {
        busy.value = false;
    }
}

async function remove(role: CustomRole) {
    if (!confirm(
        role.users_count > 0
            ? `${role.users_count} account(s) hold this role. Deleting it puts them back on their built-in permissions. Continue?`
            : `Delete the ${role.name} role?`,
    )) return;

    busy.value = true;
    problem.value = null;

    try {
        notice.value = await deleteRole(role.id);
        await refreshAfter(queryClient, 'roles');
    } catch (e) {
        problem.value = describeError(e).message;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div>
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <h1 class="text-xl font-semibold tracking-tight text-ink">Roles</h1>

            <button
                type="button"
                class="ms-auto rounded bg-accent px-3 py-1.5 text-sm font-medium text-on-accent transition hover:brightness-110"
                @click="open(null)"
            >
                New role
            </button>
        </div>

        <p class="mb-4 max-w-prose text-body">
            A role decides what somebody may do. It refines one of the built-in roles rather than
            replacing it — which branch a person sees still comes from that built-in role and
            cannot be changed here.
        </p>

        <p v-if="notice" class="mb-3 rounded border border-ok-soft bg-ok-soft px-3 py-2 text-sm text-ok">{{ notice }}</p>
        <p v-if="problem" class="mb-3 rounded border border-crit bg-crit-soft px-3 py-2 text-sm text-crit">{{ problem }}</p>

        <p v-if="isPending || loadingCatalogue" class="text-muted">Loading…</p>

        <p v-else-if="error" class="rounded border border-crit bg-crit-soft px-3 py-2 text-sm text-crit">
            {{ describeError(error).message }}
        </p>

        <p v-else-if="!(roles ?? []).length" class="rounded-lg border border-line bg-surface px-4 py-8 text-center text-muted">
            No custom roles yet. Everybody uses their built-in permissions.
        </p>

        <div v-else class="grid gap-3 md:grid-cols-2">
            <article v-for="role in roles" :key="role.id" class="rounded-lg border border-line-strong bg-surface p-4">
                <div class="flex flex-wrap items-start gap-2">
                    <div>
                        <h2 class="font-semibold text-ink">{{ role.name }}</h2>
                        <p class="text-xs text-muted">
                            Based on {{ role.base_role_label }} · {{ role.users_count }} account(s)
                        </p>
                    </div>

                    <span
                        class="ms-auto rounded px-2 py-0.5 text-xs font-medium"
                        :class="role.status ? 'bg-ok-soft text-ok' : 'bg-warn-soft text-warn'"
                    >
                        {{ role.status ? 'In use' : 'Switched off' }}
                    </span>
                </div>

                <p v-if="role.description" class="mt-2 text-sm text-body">{{ role.description }}</p>

                <!-- Grouped, so a long list reads as "Complaints, everything;
                     Payments, view only" rather than thirty ability names. -->
                <dl class="mt-3 flex flex-col gap-1 text-xs">
                    <div v-for="(abilities, module) in role.by_module" :key="module" class="flex gap-2">
                        <dt class="w-28 shrink-0 text-muted">{{ module }}</dt>
                        <dd class="text-body">{{ abilities.length }} of them</dd>
                    </div>
                    <p v-if="!Object.keys(role.by_module).length" class="text-warn">
                        Nothing granted — anybody holding this can sign in and see nothing.
                    </p>
                </dl>

                <div class="mt-3 flex gap-2 border-t border-line pt-3">
                    <button type="button" class="rounded border border-line-strong px-3 py-1.5 text-sm text-body transition hover:bg-sunk" @click="open(role)">
                        Edit
                    </button>
                    <button type="button" :disabled="busy" class="rounded px-3 py-1.5 text-sm text-crit transition hover:bg-crit-soft disabled:opacity-50" @click="remove(role)">
                        Delete
                    </button>
                </div>
            </article>
        </div>

        <!-- The builder -->
        <div v-if="editing || creating" class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-black/40 p-4 pt-10" @click.self="close">
            <form class="w-full max-w-2xl rounded-lg border border-line-strong bg-surface p-5 shadow-xl" @submit.prevent="save">
                <h2 class="mb-4 text-lg font-semibold text-ink">{{ creating ? 'New role' : `Edit ${editing!.name}` }}</h2>

                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Name</span>
                        <input v-model.trim="form.name" type="text" required placeholder="Supervisor" class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent" />
                    </label>

                    <label class="block">
                        <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Behaves like</span>
                        <select v-model="form.base_role" class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">
                            <option v-for="base in baseRoles" :key="base.value" :value="base.value">{{ base.label }}</option>
                        </select>
                        <span class="mt-1 block text-xs text-faint">Decides which branch they see. Not editable below.</span>
                    </label>

                    <label class="block sm:col-span-2">
                        <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">What this role is for</span>
                        <input v-model.trim="form.description" type="text" placeholder="Runs the day but does not touch money" class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent" />
                    </label>
                </div>

                <div class="mt-4 flex flex-wrap items-center gap-3 border-t border-line pt-3">
                    <p class="text-sm font-medium text-ink">
                        {{ form.abilities.length }} permission(s) chosen
                    </p>

                    <button
                        type="button"
                        class="text-xs text-accent underline"
                        @click="form.abilities = [...baseAbilities]"
                    >
                        Start from what a {{ baseRoles.find((b) => b.value === form.base_role)?.label }} gets
                    </button>

                    <button type="button" class="text-xs text-muted underline hover:text-ink" @click="form.abilities = []">
                        Clear all
                    </button>

                    <label class="ms-auto flex items-center gap-2 text-sm text-body">
                        <input v-model="form.status" type="checkbox" class="accent-[var(--accent)]" />
                        In use
                    </label>
                </div>

                <div class="mt-3 max-h-80 overflow-y-auto rounded border border-line p-3">
                    <fieldset v-for="mod in modules" :key="mod.module" class="mb-4 last:mb-0">
                        <legend class="mb-1.5 flex w-full items-center gap-2">
                            <span class="text-xs font-semibold uppercase tracking-wide text-muted">{{ mod.module }}</span>
                            <button
                                type="button"
                                class="text-xs text-accent underline"
                                @click="toggleModule(mod.abilities.map((a) => a.key))"
                            >
                                all
                            </button>
                        </legend>

                        <div class="grid gap-1 sm:grid-cols-2">
                            <label
                                v-for="ability in mod.abilities"
                                :key="ability.key"
                                class="flex cursor-pointer items-start gap-2 rounded px-2 py-1 text-sm text-body transition hover:bg-sunk"
                            >
                                <input
                                    type="checkbox"
                                    class="mt-0.5 accent-[var(--accent)]"
                                    :checked="form.abilities.includes(ability.key)"
                                    @change="toggle(ability.key)"
                                />
                                {{ ability.label }}
                            </label>
                        </div>
                    </fieldset>
                </div>

                <p v-if="problem" class="mt-3 rounded bg-crit-soft px-3 py-2 text-sm text-crit">{{ problem }}</p>

                <div class="mt-4 flex gap-2">
                    <button type="submit" :disabled="busy" class="rounded bg-accent px-4 py-2 text-sm font-medium text-on-accent transition hover:brightness-110 disabled:opacity-60">
                        {{ busy ? 'Saving…' : 'Save role' }}
                    </button>
                    <button type="button" class="rounded border border-line-strong px-4 py-2 text-sm text-body transition hover:bg-sunk" @click="close">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>
