import { api } from '@/shared/api/client';

/**
 * One payment, in full.
 *
 * Shared between the office's payments list and the customer's own, because
 * both open the same panel. What comes back differs by who is asking - the
 * server decides that, not this file.
 */

export interface PaymentTimelineEvent {
    at: string | null;
    what: string;
    detail: string;
}

export interface PaymentSibling {
    id: string;
    invoice_number: string | null;
    status: string;
    status_label: string;
    amount: number;
    paid_at: string | null;
    purpose_label: string;
}

export interface PaymentDetail {
    id: string;
    invoice_number: string | null;
    status: string;
    status_label: string;
    purpose_label: string;
    amount: number;
    amount_formatted: string;
    currency: string;
    /** Derived from how it was taken, not stored - so it cannot disagree. */
    channel: 'online' | 'offline';
    method: string | null;
    recorded_by_hand: boolean;
    gateway: {
        name: string | null;
        order_id: string | null;
        payment_id: string | null;
        reference: string | null;
    };
    timeline: PaymentTimelineEvent[];
    verified_by: string | null;
    verified_at: string | null;
    notes: string | null;
    customer: { id: string; name: string; phone: string | null; sector: string | null } | null;
    subscription: {
        id: string;
        sequence: number;
        status: string;
        registration: string | null;
        car_model: string | null;
        package: string | null;
        service_type: string | null;
        duration: string | null;
        period: { start: string | null; end: string | null };
        amount: number;
        paid: number;
        outstanding: number;
    } | null;
    others_on_this_plan: PaymentSibling[];
    has_receipt: boolean;
}

export async function fetchPaymentDetail(id: string): Promise<PaymentDetail> {
    const { data } = await api.get(`/payments/${id}/detail`);
    return data.data;
}

/** Every payment against one plan, for the button on a subscription row. */
export async function fetchPaymentsForSubscription(subscriptionId: string): Promise<PaymentSibling[]> {
    const { data } = await api.get('/payments', { params: { subscription_id: subscriptionId, per_page: 100 } });

    return (data.data as Array<Record<string, unknown>>).map((row) => ({
        id: row.id as string,
        invoice_number: (row.invoice_number as string) ?? null,
        status: row.status as string,
        status_label: row.status_label as string,
        amount: row.amount as number,
        paid_at: (row.paid_at as string) ?? null,
        purpose_label: row.purpose_label as string,
    }));
}

export function money(rupees: number): string {
    return new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR' }).format(rupees);
}

export function statusTone(status: string): string {
    switch (status) {
        case 'captured':
            return 'bg-ok-soft text-ok';
        case 'failed':
            return 'bg-crit-soft text-crit';
        case 'refunded':
            return 'bg-info-soft text-info';
        default:
            // In flight, or abandoned: neither good news nor a failure yet.
            return 'bg-warn-soft text-warn';
    }
}
