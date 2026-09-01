import { api } from '@/shared/api/client';
import type { RenewalTiming } from '@/shared/types';

/**
 * The customer's own pages.
 *
 * Nothing here takes an id. What comes back is decided by the session, which is
 * also why none of these calls can be pointed at somebody else.
 */

export interface PortalProfile {
    name: string;
    phone: string | null;
    email: string | null;
    house_no: string | null;
    address: string | null;
    preferred_time: string | null;
    sector: string | null;
    society: string | null;
}

export interface PortalPlan {
    id: string;
    sequence: number;
    status: { value: string; label: string };
    is_expired: boolean;
    period: { start: string | null; end: string | null };
    /** Early, due today, or overdue. Worked out on the server. */
    timing: RenewalTiming;
    amount: { paise: number; formatted: string };
    paid: { paise: number; formatted: string };
    cloth: { enabled: boolean; balance: number };
    vehicle?: { id: string; registration: string; model: string | null; cleaner: { id: string; name: string } | null };
    package?: string | null;
    service_type?: string | null;
    duration?: string | null;
}

export interface PortalOverview {
    profile: PortalProfile;
    vehicles: Array<{ id: string; registration: string; model: string | null }>;
    plans: PortalPlan[];
    totals: { active: number; due_soon: number; unpaid_paise: number };
}

export async function fetchOverview(): Promise<PortalOverview> {
    const { data } = await api.get('/portal/overview');
    return data.data;
}

export async function fetchPayments(): Promise<{
    data: Array<Record<string, unknown>>;
    meta: { total: number; current_page: number; last_page: number };
}> {
    const { data } = await api.get('/portal/payments');
    return data;
}

export async function saveProfile(changes: Partial<PortalProfile>): Promise<PortalProfile> {
    const { data } = await api.patch('/portal/profile', changes);
    return data.data;
}

/*
 * Paying is not here. Renewals and cloth top ups go through
 * `@/shared/api/checkout`, which every other payment in the application already uses -
 * a second implementation would be a second place for the simulated gateway,
 * the signature callback and the careful wording of a failed one to drift.
 */
