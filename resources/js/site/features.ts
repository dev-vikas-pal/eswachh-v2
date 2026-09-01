import { readonly, ref } from 'vue';
import { api } from '@/shared/api/client';

/**
 * Which parts of the site the business is running.
 *
 * The blog and the team page are built and working, and switched off until
 * there is something worth putting on either. That decision has to reach the
 * menu, the router and the pages themselves, so it is asked once here and
 * shared - the same shape as the session check beside it, and for the same
 * reason: the marketing pages must not wait on a round trip before rendering.
 *
 * The server is the one that enforces this. Its endpoints answer 404 with a
 * feature off, so a link typed by hand gets nowhere whatever this says; what
 * this decides is only whether to offer the link.
 */
export interface SiteFeatures {
    blog: boolean;
    team: boolean;
    cloth_service: boolean;
}

/*
 * Everything on until told otherwise.
 *
 * Deliberately the opposite of the server's fail-closed default. A failed
 * request here means the menu is drawn before the answer arrives, and hiding
 * pages that do exist - then putting them back a moment later under somebody's
 * cursor - is worse than briefly offering a link that lands on a redirect.
 */
const DEFAULTS: SiteFeatures = { blog: true, team: true, cloth_service: true };

const features = ref<SiteFeatures>({ ...DEFAULTS });
const known = ref(false);

let inFlight: Promise<void> | null = null;

/**
 * Wait for the answer.
 *
 * Only the router needs this. A page opened directly from a bookmark or a
 * search result has to know before it decides whether to render, where the
 * menu can afford to correct itself a moment later.
 */
export async function whenFeaturesKnown(): Promise<void> {
    useSiteFeatures();

    await inFlight;
}

export function useSiteFeatures() {
    if (!inFlight) {
        inFlight = api.get('/public/content')
            .then(({ data }) => {
                features.value = { ...DEFAULTS, ...(data.data?.features ?? {}) };
            })
            .catch(() => {
                // Leave the defaults. A public page that cannot reach the API
                // has bigger problems than its menu.
            })
            .finally(() => {
                known.value = true;
            });
    }

    return { features: readonly(features), known: readonly(known) };
}
