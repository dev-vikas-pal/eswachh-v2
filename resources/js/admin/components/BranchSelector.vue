<script setup lang="ts">
import { useAuthStore } from '@/shared/stores/auth';

/**
 * Narrows the screens to one branch.
 *
 * A convenience only. Choosing a branch does not grant access to it: the
 * server validates the branch on every request and refuses one that is not
 * yours. Someone with a single branch never sees this control.
 */
const auth = useAuthStore();

function onChange(event: Event) {
    const value = (event.target as HTMLSelectElement).value;
    auth.selectBranch(value === '' ? null : value);
}
</script>

<template>
    <label v-if="auth.canSwitchBranch" class="flex items-center gap-2">
        <span class="text-xs font-medium uppercase tracking-wide text-muted">Branch</span>
        <select
            :value="auth.selectedBranchId ?? ''"
            class="rounded border border-line-strong bg-surface px-2 py-1.5 text-sm focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
            @change="onChange"
        >
            <option value="">
                {{ auth.user?.sees_all_branches ? 'All branches' : 'All my branches' }}
            </option>
            <option v-for="branch in auth.branches" :key="branch.id" :value="branch.id">
                {{ branch.name }}
            </option>
        </select>
    </label>

    <span v-else-if="auth.branches.length === 1" class="text-sm text-muted">
        {{ auth.branches[0].name }}
    </span>
</template>
