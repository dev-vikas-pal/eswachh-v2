import { api } from '@/shared/api/client';

/**
 * Every subscription endpoint the admin screens call, in one place.
 *
 * Kept out of the components so a route change is a single edit rather than a
 * search through six templates.
 */
export interface SubscriptionFilters {
    search?: string;
    status?: string;
    expired?: boolean;
    unassigned?: boolean;
    sector_id?: string;
    package_id?: string;
    cleaner_id?: string;
    renew_from?: string;
    renew_to?: string;
}

export async function listSubscriptions(params: {
    filters: SubscriptionFilters;
    page: number;
    sort: string;
    direction: string;
}) {
    const filter: Record<string, unknown> = {};

    for (const [key, value] of Object.entries(params.filters)) {
        // Blank means "no filter", not "match blank".
        if (value === '' || value === false || value === undefined || value === null) continue;
        filter[key] = value === true ? 1 : value;
    }

    const { data } = await api.get('/subscriptions', {
        params: {
            filter,
            page: params.page,
            sort: params.sort,
            direction: params.direction,
        },
    });

    return data;
}

export async function bulkAssignCleaner(ids: string[], cleanerId: string | null) {
    const { data } = await api.post('/subscriptions-bulk/cleaner', { ids, cleaner_id: cleanerId });
    return data;
}

export async function bulkSendMessage(ids: string[], templateKey: string) {
    const { data } = await api.post('/subscriptions-bulk/message', { ids, template_key: templateKey });
    return data;
}

export async function bulkTemplates() {
    const { data } = await api.get('/subscriptions-bulk/templates');
    return data.data as Array<{ key: string; name: string; description: string | null; preview: string }>;
}

export async function cleanersForBranch() {
    const { data } = await api.get('/users', { params: { role: 'cleaner', per_page: 100 } });
    return data.data as Array<{ id: string; name: string }>;
}
