import { readonly, ref } from 'vue';
import { api } from '@/shared/api/client';

/**
 * Whether somebody is already signed in, for the public site.
 *
 * The marketing pages carry no auth store on purpose - they must render without
 * waiting on anything - but they were pretending nobody was ever signed in,
 * which showed a signed-in customer a "Sign in" button and let them start a
 * second signup for a car they already have.
 *
 * So: one small check, asked once per page load, and the header updates when it
 * comes back. Nothing waits for it, and a failure means "not signed in", which
 * is the correct assumption on a public page.
 */

export interface SiteSession {
    name: string;
    role: string;
    /** Where their own pages are, which differs for staff and customers. */
    home: string;
}

const session = ref<SiteSession | null>(null);
const checked = ref(false);

let inFlight: Promise<void> | null = null;

export function useSiteSession() {
    // Shared across every component that asks, so a header and a page do not
    // make the same request twice.
    if (!inFlight) {
        inFlight = api.get('/me')
            .then(({ data }) => {
                const role = data.data?.role?.value ?? '';

                session.value = {
                    name: data.data?.name ?? '',
                    role,
                    home: role === 'customer' ? '/my/plans' : '/app/dashboard',
                };
            })
            .catch(() => {
                // Not signed in. Not an error on a public page.
                session.value = null;
            })
            .finally(() => {
                checked.value = true;
            });
    }

    return { session: readonly(session), checked: readonly(checked) };
}

/**
 * Forget what we know, so a sign out taken elsewhere is not remembered by a
 * public page that happens to still be open.
 */
export function forgetSiteSession(): void {
    session.value = null;
    checked.value = false;
    inFlight = null;
}
