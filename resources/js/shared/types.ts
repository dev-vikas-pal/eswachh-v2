/**
 * Shapes the API returns. Kept in one file so a change to a response is a
 * compile error rather than a runtime surprise.
 */

/** A territory. Staff are assigned these; a customer sits in one. */
export interface Sector {
    id: string;
    name: string;
}

export type ThemeChoice = 'system' | 'light' | 'dark';
export type MenuPosition = 'top' | 'left';
export type Density = 'comfortable' | 'compact';
export type SidebarWidth = 'wide' | 'narrow';

/** How one person likes the interface arranged. Stored against the user. */
export interface UserSettings {
    theme: ThemeChoice;
    menu_position: MenuPosition;
    density: Density;
    /** Words or icons only, on a wide screen. */
    sidebar: SidebarWidth;
}

export interface AuthUser {
    id: string;
    name: string;
    email: string | null;
    phone: string | null;
    role: { value: string; label: string };
    /** For rendering only. The server authorises every request regardless. */
    abilities: string[];
    sees_all_sectors: boolean;
    settings: UserSettings;
    branch?: { id: string; name: string };
}

export interface DashboardData {
    /** The only block a date range applies to. */
    period: { from: string; to: string; revenue_paise: number; payments: number; new_plans: number };
    subscriptions: {
        active: number;
        current: number;
        expired: number;
        hold: number;
        unassigned: number;
    };
    people: { customers: number; cleaners: number };
    vehicles: { total: number };
    as_at: string;
}

export interface Money {
    paise: number;
    /** Formatted on the server so the client never does currency arithmetic. */
    formatted: string;
}

/**
 * How a plan stands against its own end date.
 *
 * Mirrors Subscription::renewalTiming() on the server. The component that
 * renders it re-exports this name, so a screen only has to import one of them.
 */
export interface RenewalTiming {
    renews_on: string | null;
    days_remaining: number | null;
    early: boolean;
    due_today: boolean;
    overdue: boolean;
    days_overdue: number;
    /**
     * What renewing right now would do to the dates.
     *
     * 'end_date' carries on from where the last term finished; 'today' starts
     * afresh, which happens only when the plan is so far past its date that
     * carrying on would issue a term that had already run out. Decided on the
     * server because it depends on how long a term is.
     */
    starts_from: 'end_date' | 'today';
    next_period_start: string;
    next_period_end: string;
}

export interface Subscription {
    id: string;
    sequence: number;
    status: { value: string; label: string };
    is_expired: boolean;
    period: { start: string | null; end: string | null };
    /**
     * Early, due today, or overdue — worked out on the server so the four
     * screens that offer a renewal cannot disagree about it.
     */
    timing: RenewalTiming;
    amount: Money;
    paid: Money;
    cloth: { enabled: boolean; balance: number };
    vehicle?: {
        id: string;
        registration: string;
        vehicle_model_id?: string | null;
        model: string | null;
        cleaner: { id: string; name: string } | null;
    };
    customer?: { id: string; name: string; phone: string | null; society_id?: string | null };
    package?: string | null;
    /**
     * The most recent payment against this plan.
     *
     * One payment, not the list: a plan renewed six times has six, and sending
     * all of them on every row of a paginated list to show the newest would be
     * waste. The rest are a click away.
     */
    last_payment?: {
        id: string;
        invoice_number: string | null;
        amount: number;
        method: string | null;
        paid_at: string | null;
        order_type: 'online' | 'offline';
        recorded_by_hand: boolean;
    } | null;
    created_at: string | null;
}

/**
 * What every list response says about its own ordering.
 *
 * The server decides: it holds the whitelist, so it is the only thing that
 * knows whether the column asked for was allowed.
 */
export interface SortMeta {
    sortable: string[];
    sort: string;
    direction: 'asc' | 'desc';
}

export interface PageMeta extends SortMeta {
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
}

export interface Paginated<T> {
    data: T[];
    meta: PageMeta;
}

export interface Payment {
    id: string;
    invoice_number: string | null;
    status: { value: string; label: string } | string;
    status_label: string;
    purpose: string;
    purpose_label: string;
    amount_paise: number;
    amount: number;
    currency: string;
    method: string | null;
    reference: string | null;
    paid_at: string | null;
    gateway_order_id: string | null;
    gateway_payment_id: string | null;
    recorded_by_hand: boolean;
    notes: string | null;
    customer?: { id: string; name: string; phone: string | null };
    subscription_id: string | null;
    branch_id: string | null;
    created_at: string | null;
}

export interface PaymentPage {
    data: Payment[];
    /** Revenue for the same filter, so screen and total always agree. */
    meta: PageMeta & { total_captured_paise: number };
}

export interface Labelled {
    value: string;
    label: string;
}

export interface ComplaintEvent {
    id: string;
    type: string;
    from_status: string | null;
    to_status: string | null;
    note: string | null;
    actor: { id: string; name: string } | null;
    created_at: string | null;
}

export interface Complaint {
    id: string;
    reference: string;
    status: Labelled;
    category: Labelled;
    priority: Labelled;
    description: string;
    /** Derived server-side from due_at and the clock, never stored. */
    is_overdue: boolean;
    due_at: string | null;
    age_hours: number;
    reopened_count: number;
    assignee?: { id: string; name: string } | null;
    assigned_at: string | null;
    resolution_note: string | null;
    resolved_at: string | null;
    closed_at: string | null;
    customer?: { id: string; name: string; phone: string | null };
    vehicle?: { id: string; registration: string } | null;
    events?: ComplaintEvent[];
    branch_id: string | null;
    created_at: string | null;
}

export interface ComplaintPage {
    data: Complaint[];
    /** live and overdue drive the counts above the table. */
    meta: PageMeta & { live: number; overdue: number };
}

export interface CoverageRow {
    date: string;
    cleaner: { id: string; name: string };
    due: number;
    cleaned: number;
    /** Cars we failed, as opposed to cars we could not help. */
    failed: number;
    not_our_fault: number;
    /** Due, but nobody said what happened at all. */
    unaccounted: number;
    attendance: string | null;
    attendance_label: string | null;
    marked_late: boolean;
    unmarked: boolean;
}

export interface Coverage {
    date: string;
    cleaners: CoverageRow[];
    totals: {
        due: number;
        cleaned: number;
        failed: number;
        unaccounted: number;
        unmarked_cleaners: number;
    };
}
