<script setup lang="ts">
import { useAuthStore } from '@/shared/stores/auth';

/**
 * Narrows the screens to one sector.
 *
 * A convenience only. Choosing a sector does not grant access to it: the server
 * works out what this person may see from their user_sector assignments and
 * refuses a sector that is not among them. Somebody covering a single sector
 * never sees this control - there is nothing to choose between.
 */
const auth = useAuthStore();

function onChange(event: Event) {
    const value = (event.target as HTMLSelectElement).value;
    auth.selectSector(value === '' ? null : value);
}
</script>

<template>
    <label v-if="auth.canSwitchSector" class="flex items-center gap-2">
        <span class="text-xs font-medium uppercase tracking-wide text-muted">Sector</span>
        <select
            :value="auth.selectedSectorId ?? ''"
            class="rounded border border-line-strong bg-surface px-2 py-1.5 text-sm focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
            @change="onChange"
        >
            <option value="">
                {{ auth.user?.sees_all_sectors ? 'All sectors' : 'All my sectors' }}
            </option>
            <option v-for="sector in auth.sectors" :key="sector.id" :value="sector.id">
                {{ sector.name }}
            </option>
        </select>
    </label>

    <span v-else-if="auth.sectors.length === 1" class="text-sm text-muted">
        {{ auth.sectors[0].name }}
    </span>
</template>
