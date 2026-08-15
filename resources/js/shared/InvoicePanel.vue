<script setup lang="ts">
import { computed } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import { api, describeError } from '@/shared/api/client';

/**
 * A printable receipt for one payment.
 *
 * Printed with the browser rather than built as a PDF on the server. A receipt
 * is read once and thrown away; a PDF pipeline is a dependency, a font problem
 * and a temp directory to keep clean, in exchange for nothing the customer can
 * tell apart.
 */
const props = defineProps<{ paymentId: string }>();
defineEmits<{ (e: 'close'): void }>();

const { data, isLoading, error } = useQuery({
    queryKey: computed(() => ['invoice', props.paymentId]),
    queryFn: async () => (await api.get(`/payments/${props.paymentId}/invoice`)).data.data,
    retry: false,
});

function money(rupees: number): string {
    return new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR' }).format(rupees);
}

/** `window` is not in template scope, so the print button calls this. */
function print(): void {
    window.print();
}

/**
 * Download it.
 *
 * The browser's own "print to PDF" rather than a PDF built on the server: a
 * receipt is read once and filed, and a PDF pipeline is a dependency, a font
 * problem and a temp directory to keep clean in exchange for nothing the
 * customer can tell apart. The print dialog offers "Save as PDF" everywhere.
 *
 * The plain-text copy beside it is for the case that actually comes up -
 * somebody needing the figures in an email or a WhatsApp message.
 */
function download(): void {
    if (!data.value) return;

    const lines = [
        data.value.from.name,
        data.value.from.address,
        data.value.from.gstin ? `GSTIN ${data.value.from.gstin}` : '',
        '',
        `Receipt ${data.value.number}`,
        `Date    ${data.value.issued_on ?? ''}`,
        '',
        `Billed to: ${data.value.to.name}`,
        data.value.to.address,
        data.value.to.phone,
        '',
        ...data.value.lines.map((line: { description: string; amount: number }) =>
            `${line.description}   ${money(line.amount)}`),
        '',
        `Total paid: ${data.value.total_formatted}`,
        data.value.reference ? `Reference: ${data.value.reference}` : '',
        '',
        data.value.footer ?? '',
    ].filter((line) => line !== '' || true);

    const blob = new Blob([lines.join('\n')], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);

    const link = document.createElement('a');
    link.href = url;
    link.download = `receipt-${data.value.number.replace(/\//g, '-')}.txt`;
    link.click();

    // Released straight away: the download has already been handed to the
    // browser, and holding the object URL leaks the blob for the session.
    URL.revokeObjectURL(url);
}
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4 print:static print:bg-transparent print:p-0">
        <div class="invoice-sheet w-full max-w-2xl rounded-lg border border-line-strong bg-surface shadow-xl print:border-0 print:shadow-none">
            <header class="flex items-center gap-2 border-b border-line px-5 py-3 print:hidden">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-muted">Receipt</h2>

                <button
                    type="button"
                    class="ms-auto rounded bg-accent px-3 py-1.5 text-sm font-medium text-on-accent transition hover:brightness-110 disabled:opacity-60"
                    :disabled="!data"
                    title="The print dialog offers Save as PDF"
                    @click="print"
                >
                    Print or save as PDF
                </button>

                <button
                    type="button"
                    class="rounded border border-line-strong px-3 py-1.5 text-sm text-body transition hover:bg-sunk disabled:opacity-60"
                    :disabled="!data"
                    title="A plain text copy, for pasting into an email"
                    @click="download"
                >
                    Download
                </button>

                <button
                    type="button"
                    class="rounded border border-line-strong px-3 py-1.5 text-sm text-body transition hover:bg-sunk"
                    @click="$emit('close')"
                >
                    Close
                </button>
            </header>

            <p v-if="isLoading" class="px-5 py-8 text-sm text-muted">Loading…</p>

            <p v-else-if="error" class="m-5 rounded border border-bad-soft bg-bad-soft px-3 py-2 text-sm text-bad">
                {{ describeError(error).message }}
            </p>

            <div v-else class="px-6 py-6">
                <div class="flex flex-wrap items-start gap-4">
                    <div>
                        <h3 class="text-lg font-bold text-ink">{{ data.from.name }}</h3>
                        <p class="mt-0.5 whitespace-pre-line text-xs text-muted">{{ data.from.address }}</p>
                        <p v-if="data.from.gstin" class="text-xs text-muted">GSTIN {{ data.from.gstin }}</p>
                        <p class="text-xs text-muted">{{ data.from.phone }} · {{ data.from.email }}</p>
                    </div>

                    <div class="ms-auto text-right">
                        <p class="text-xs uppercase tracking-wide text-muted">Receipt</p>
                        <p class="font-semibold tabular-nums text-ink">{{ data.number }}</p>
                        <p class="text-xs tabular-nums text-muted">{{ data.issued_on }}</p>
                    </div>
                </div>

                <div class="mt-6 rounded border border-line p-3">
                    <p class="text-xs uppercase tracking-wide text-muted">Billed to</p>
                    <p class="font-medium text-ink">{{ data.to.name }}</p>
                    <p class="text-sm text-body">{{ data.to.address }}</p>
                    <p class="text-sm tabular-nums text-body">{{ data.to.phone }}</p>
                </div>

                <table class="mt-6 w-full text-sm">
                    <thead class="border-b border-line text-left text-xs uppercase tracking-wide text-muted">
                        <tr>
                            <th scope="col" class="pb-2">Description</th>
                            <th scope="col" class="pb-2 text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(line, i) in data.lines" :key="i" class="border-b border-line">
                            <td class="py-2 text-body">
                                {{ line.description }}
                                <span v-if="line.period" class="block text-xs tabular-nums text-muted">
                                    {{ line.period.start }} to {{ line.period.end }}
                                </span>
                            </td>
                            <td class="py-2 text-right tabular-nums text-ink">{{ money(line.amount) }}</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td class="pt-3 text-right font-semibold text-ink">Total paid</td>
                            <td class="pt-3 text-right text-lg font-bold tabular-nums text-ink">
                                {{ data.total_formatted }}
                            </td>
                        </tr>
                    </tfoot>
                </table>

                <p class="mt-4 text-xs text-muted">
                    Paid by {{ data.method || (data.paid_by_hand ? 'cash' : 'card') }}<span v-if="data.reference">, reference {{ data.reference }}</span>.
                </p>

                <p v-if="data.footer" class="mt-4 border-t border-line pt-3 text-xs text-faint">{{ data.footer }}</p>
            </div>
        </div>
    </div>
</template>

<style>
/*
 * The classic visibility trick rather than display:none on siblings: it keeps
 * the sheet's own layout intact while hiding everything around it, which
 * display:none on ancestors does not.
 */
@media print {
    body * {
        visibility: hidden;
    }

    .invoice-sheet,
    .invoice-sheet * {
        visibility: visible;
    }

    .invoice-sheet {
        position: absolute;
        inset: 0;
        max-width: none;
    }
}
</style>
