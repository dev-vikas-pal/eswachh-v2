import { computed, ref, watch } from 'vue';
import { useQuery, keepPreviousData } from '@tanstack/vue-query';
import { useRouter } from 'vue-router';
import { api } from '@/shared/api/client';
import { useAuthStore } from '@/shared/stores/auth';
import type { Payment, PaymentPage } from '@/shared/types';

/**
 * Everything the Payments screen does, apart from drawing itself.
 *
 * Paired with PaymentsView.vue and named for it, so the two are obviously one
 * screen. The component keeps the markup and the bindings; every piece of state
 * and every decision lives here, where it can be read without scrolling past a
 * table and, if it ever needs to be, tested without mounting anything.
 */
export function usePaymentsScreen() {
    const auth = useAuthStore();
    const router = useRouter();

    /** Which payment the receipt is open for, and which the full detail. */
    const receiptFor = ref<string | null>(null);
    const detailFor = ref<string | null>(null);

    const search = ref('');
    const status = ref('');
    const from = ref('');
    const to = ref('');
    const page = ref(1);
    const sort = ref('created');
    const direction = ref<'asc' | 'desc'>('asc');

    const { data, isPending, isError, isFetching } = useQuery({
        queryKey: computed(() => [
            'payments', auth.selectedBranchId, search.value, status.value,
            from.value, to.value, page.value, sort.value, direction.value,
        ]),
        placeholderData: keepPreviousData,
        queryFn: async (): Promise<PaymentPage> => {
            const { data } = await api.get('/payments', {
                params: {
                    page: page.value,
                    search: search.value || undefined,
                    status: status.value || undefined,
                    from: from.value || undefined,
                    to: to.value || undefined,
                    sort: sort.value,
                    direction: direction.value,
                },
            });

            return data;
        },
    });

    const rows = computed(() => data.value?.data ?? []);
    const meta = computed(() => data.value?.meta);

    /**
     * What the server says it sorted by, falling back to what we asked for
     * while the first response is in flight - otherwise the arrow flickers on
     * every click.
     */
    const activeSort = computed(() => meta.value?.sort ?? sort.value);
    const activeDirection = computed<'asc' | 'desc'>(() => meta.value?.direction ?? direction.value);

    /**
     * The one figure people act on, so it is stated in full rather than
     * abbreviated, and it always describes exactly the rows below it.
     */
    const captured = computed(() =>
        new Intl.NumberFormat('en-IN', {
            style: 'currency',
            currency: 'INR',
            maximumFractionDigits: 0,
        }).format((meta.value?.total_captured_paise ?? 0) / 100),
    );

    watch([search, status, from, to, () => auth.selectedBranchId], () => {
        page.value = 1;
    });

    function onSort(field: string, next: 'asc' | 'desc') {
        sort.value = field;
        direction.value = next;
        page.value = 1;
    }

    /** One filter, one pair of dates, shared with every other list. */
    function onPeriod(range: { from: string; to: string }) {
        from.value = range.from;
        to.value = range.to;
        page.value = 1;
    }

    /**
     * Follow a payment back to the order it paid for.
     *
     * The subscriptions list opens on that one plan rather than a separate
     * screen: the row there already carries every action somebody would want
     * next - edit it, record another payment, message the customer.
     */
    async function openSubscription(id: string) {
        detailFor.value = null;
        await router.push({ name: 'subscriptions', query: { plan: id } });
    }

    function money(paise: number): string {
        return new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR' }).format(paise / 100);
    }

    /** The status arrives as a string from some endpoints and an object from others. */
    function statusValue(row: Payment): string {
        return typeof row.status === 'string' ? row.status : row.status.value;
    }

    function statusClass(row: Payment): string {
        switch (statusValue(row)) {
            case 'captured':
                return 'bg-ok-soft text-ok';
            case 'failed':
                return 'bg-crit-soft text-crit';
            case 'refunded':
                return 'bg-info-soft text-info';
            default:
                // Still in flight, or abandoned. Amber, because it is neither
                // good news nor a failure yet.
                return 'bg-warn-soft text-warn';
        }
    }

    function when(iso: string | null): string {
        return iso
            ? new Date(iso).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' })
            : '—';
    }

    return {
        // state
        receiptFor, detailFor, search, status, page,
        // data
        rows, meta, captured, isPending, isError, isFetching,
        activeSort, activeDirection,
        // actions
        onSort, onPeriod, openSubscription,
        // formatting
        money, statusValue, statusClass, when,
    };
}
