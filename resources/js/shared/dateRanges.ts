/**
 * The date ranges every list screen offers.
 *
 * One list, used by Orders, Payments, Messages, the Dashboard and Reports, so
 * "this month" means the same thing on all of them. Two screens that each work
 * out their own month are two screens that will eventually disagree by a day.
 *
 * Dates are produced as YYYY-MM-DD in the *browser's* timezone, deliberately.
 * The office is in one place and thinks in local days; converting to UTC here
 * would put an 11pm payment on tomorrow's report.
 */

export type RangeKey =
    | 'all'
    | 'today'
    | 'yesterday'
    | 'this_week'
    | 'this_month'
    | 'last_3_months'
    | 'last_6_months'
    | 'custom';

export interface DateRange {
    from: string;
    to: string;
}

export const RANGE_OPTIONS: Array<{ value: RangeKey; label: string }> = [
    { value: 'all', label: 'Any date' },
    { value: 'today', label: 'Today' },
    { value: 'yesterday', label: 'Yesterday' },
    { value: 'this_week', label: 'This week' },
    { value: 'this_month', label: 'This month' },
    { value: 'last_3_months', label: 'Last 3 months' },
    { value: 'last_6_months', label: 'Last 6 months' },
    { value: 'custom', label: 'Custom range' },
];

/** YYYY-MM-DD for a local date, without going near UTC. */
export function toIsoDate(date: Date): string {
    const month = `${date.getMonth() + 1}`.padStart(2, '0');
    const day = `${date.getDate()}`.padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
}

function daysAgo(n: number): Date {
    const d = new Date();
    d.setDate(d.getDate() - n);
    return d;
}

/**
 * Turn a preset into two dates.
 *
 * Returns null for "any date" and for a custom range - the first means no
 * filter at all, and the second is whatever the two calendars say.
 */
export function resolveRange(key: RangeKey): DateRange | null {
    const today = new Date();

    switch (key) {
        case 'today':
            return { from: toIsoDate(today), to: toIsoDate(today) };

        case 'yesterday': {
            const y = daysAgo(1);
            return { from: toIsoDate(y), to: toIsoDate(y) };
        }

        case 'this_week': {
            /*
             * Monday to today. Monday rather than Sunday because the round runs
             * Monday to Saturday, so a week that starts on Sunday would put the
             * quietest day at the front of every weekly figure.
             */
            const start = new Date(today);
            const weekday = (start.getDay() + 6) % 7;
            start.setDate(start.getDate() - weekday);

            return { from: toIsoDate(start), to: toIsoDate(today) };
        }

        case 'this_month': {
            const start = new Date(today.getFullYear(), today.getMonth(), 1);
            return { from: toIsoDate(start), to: toIsoDate(today) };
        }

        case 'last_3_months':
        case 'last_6_months': {
            /*
             * Whole months back from the first of this one, so "last 3 months"
             * is three complete months and not "ninety days", which lands
             * mid-month and makes two runs a week apart uncomparable.
             */
            const months = key === 'last_3_months' ? 3 : 6;
            const start = new Date(today.getFullYear(), today.getMonth() - (months - 1), 1);

            return { from: toIsoDate(start), to: toIsoDate(today) };
        }

        default:
            return null;
    }
}

/** How the chosen range reads once it is applied, for the summary line. */
export function describeRange(key: RangeKey, custom: DateRange): string {
    if (key === 'all') return 'any date';

    const range = key === 'custom' ? custom : resolveRange(key);

    if (!range?.from && !range?.to) return 'any date';
    if (range.from && !range.to) return `from ${range.from}`;
    if (!range.from && range.to) return `up to ${range.to}`;
    if (range.from === range.to) return range.from;

    return `${range.from} to ${range.to}`;
}
