import { api } from '@/shared/api/client';

/**
 * The customer's own complaints.
 *
 * The same endpoints the office uses. The server already narrows a list to the
 * customer records behind the signed in account and ignores any customer_id in
 * the body when a customer is the one asking, so there is nothing to pass here
 * and nothing this file could point at somebody else.
 */

export interface Complaint {
    id: string;
    reference: string | null;
    category: { value: string; label: string };
    priority: { value: string; label: string };
    status: { value: string; label: string };
    description: string;
    is_overdue: boolean;
    due_by: string | null;
    vehicle: { id: string; registration: string } | null;
    created_at: string | null;
    events?: Array<{ at: string; what: string; note: string | null; by: string | null }>;
}

export interface ComplaintOption { value: string; label: string; description?: string | null }

export async function fetchMyComplaints(): Promise<Complaint[]> {
    const { data } = await api.get('/complaints', { params: { per_page: 50 } });
    return data.data;
}

/** Categories and priorities, so the form does not hard-code the enum twice. */
export async function fetchComplaintOptions(): Promise<{
    categories: ComplaintOption[];
    priorities: ComplaintOption[];
}> {
    const { data } = await api.get('/complaints/options');
    return data.data;
}

export interface RaiseComplaint {
    category: string;
    description: string;
    vehicle_id?: string | null;
    subscription_id?: string | null;
}

export async function raiseComplaint(input: RaiseComplaint): Promise<Complaint> {
    /*
     * No customer_id and no priority. The server takes the customer from the
     * session, and how urgent something is is the business's judgement - a form
     * that lets everybody mark their own complaint critical is a form where the
     * field means nothing.
     */
    const { data } = await api.post('/complaints', input);
    return data.data;
}
