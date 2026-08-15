<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { RANGE_OPTIONS, resolveRange, type DateRange, type RangeKey } from '@/shared/dateRanges';

/**
 * The date filter every list screen uses.
 *
 * A dropdown of the ranges people actually ask for, with the two calendars kept
 * for the times they want something else. Before this, each screen had a bare
 * pair of date inputs and "this month" meant typing two dates correctly.
 *
 * The component owns only the choice. What it emits is the pair of dates the
 * caller sends to the server, so no screen has to know how a month is worked
 * out and none of them can disagree about it.
 */
const props = withDefaults(defineProps<{
    /** Starting selection, for a screen that should open on a range. */
    initial?: RangeKey;
    label?: string;
}>(), {
    initial: 'all',
    label: 'Period',
});

const emit = defineEmits<{ (e: 'change', range: DateRange): void }>();

const choice = ref<RangeKey>(props.initial);
const custom = ref<DateRange>({ from: '', to: '' });

const isCustom = computed(() => choice.value === 'custom');

/** What the caller should send. Empty strings mean "no bound". */
const current = computed<DateRange>(() => {
    if (isCustom.value) return { ...custom.value };

    return resolveRange(choice.value) ?? { from: '', to: '' };
});

/*
 * Announced whenever the answer changes, including on the first render, so a
 * screen opening on "this month" does not have to work the dates out itself.
 */
watch(current, (range) => emit('change', range), { immediate: true, deep: true });

/**
 * A custom range with the second date before the first returns nothing and
 * looks broken, so the two are kept in order as they are typed.
 */
watch(() => custom.value.from, (from) => {
    if (from && custom.value.to && custom.value.to < from) custom.value.to = from;
});

watch(() => custom.value.to, (to) => {
    if (to && custom.value.from && custom.value.from > to) custom.value.from = to;
});
</script>

<template>
    <div class="flex flex-wrap items-end gap-3">
        <label>
            <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">{{ label }}</span>
            <select
                v-model="choice"
                class="rounded border border-line-strong bg-surface px-2 py-1.5 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
            >
                <option v-for="option in RANGE_OPTIONS" :key="option.value" :value="option.value">
                    {{ option.label }}
                </option>
            </select>
        </label>

        <!-- Only the custom choice shows the calendars: two empty date boxes
             sitting beside a dropdown that already answered the question are
             the thing this component exists to remove. -->
        <template v-if="isCustom">
            <label>
                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">From</span>
                <input
                    v-model="custom.from"
                    type="date"
                    :max="custom.to || undefined"
                    class="rounded border border-line-strong bg-surface px-2 py-1.5 text-sm tabular-nums text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                />
            </label>

            <label>
                <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">To</span>
                <input
                    v-model="custom.to"
                    type="date"
                    :min="custom.from || undefined"
                    class="rounded border border-line-strong bg-surface px-2 py-1.5 text-sm tabular-nums text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                />
            </label>
        </template>

        <p v-else-if="current.from" class="pb-1.5 text-xs tabular-nums text-faint">
            {{ current.from === current.to ? current.from : `${current.from} → ${current.to}` }}
        </p>
    </div>
</template>
