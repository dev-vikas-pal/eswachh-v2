import { computed, ref, watch, type Ref } from 'vue';

/**
 * Ticked rows on a paginated list.
 *
 * Selection is cleared whenever the rows change. Carrying it across a filter
 * or a page turn means a bulk action hits rows the person can no longer see,
 * which is exactly how somebody messages the wrong hundred customers.
 */
export function useRowSelection<T extends { id: string }>(rows: Ref<T[]>) {
    const selected = ref<Set<string>>(new Set());

    watch(rows, () => selected.value = new Set());

    const count = computed(() => selected.value.size);
    const ids = computed(() => [...selected.value]);
    const any = computed(() => selected.value.size > 0);

    /** True only when every row on this page is ticked. */
    const allOnPage = computed(() =>
        rows.value.length > 0 && rows.value.every((row) => selected.value.has(row.id)),
    );

    function isSelected(id: string): boolean {
        return selected.value.has(id);
    }

    function toggle(id: string) {
        const next = new Set(selected.value);
        next.has(id) ? next.delete(id) : next.add(id);
        selected.value = next;
    }

    function toggleAll() {
        selected.value = allOnPage.value ? new Set() : new Set(rows.value.map((r) => r.id));
    }

    function clear() {
        selected.value = new Set();
    }

    return { selected, ids, count, any, allOnPage, isSelected, toggle, toggleAll, clear };
}
