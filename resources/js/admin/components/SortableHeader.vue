<script setup lang="ts">
/**
 * A table heading you can sort by.
 *
 * Sorting happens on the server, not here: a page of 25 rows sorted in the
 * browser sorts that page only, which looks right and is wrong the moment
 * there is a second page.
 */
const props = defineProps<{
    /** The key the server knows this column by. */
    field: string;
    /** Currently sorted field and direction, from the list response. */
    sort: string;
    direction: 'asc' | 'desc';
    /** Right-aligned for numbers, so the arrow sits beside the digits. */
    align?: 'left' | 'right';
}>();

const emit = defineEmits<{ (e: 'sort', field: string, direction: 'asc' | 'desc'): void }>();

function toggle() {
    // Clicking the column you are already on reverses it; a new column starts
    // ascending, which is what people expect from a name or a date.
    const next = props.sort === props.field && props.direction === 'asc' ? 'desc' : 'asc';
    emit('sort', props.field, next);
}
</script>

<template>
    <th class="px-3 py-2 font-medium" :class="align === 'right' ? 'text-right' : 'text-left'">
        <button
            type="button"
            class="inline-flex items-center gap-1 uppercase tracking-wide transition hover:text-ink focus:outline-none focus-visible:ring-2 focus-visible:ring-accent"
            :class="sort === field ? 'text-accent-ink' : ''"
            :aria-sort="sort === field ? (direction === 'asc' ? 'ascending' : 'descending') : 'none'"
            @click="toggle"
        >
            <slot />
            <svg
                v-if="sort === field"
                class="h-3 w-3 shrink-0"
                :class="direction === 'desc' ? 'rotate-180' : ''"
                viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"
            >
                <path d="M6 9.5V2.5M3 5.5 6 2.5l3 3" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            <span v-else class="h-3 w-3 shrink-0 opacity-0 transition group-hover:opacity-40" aria-hidden="true" />
        </button>
    </th>
</template>
