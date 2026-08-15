import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { api, primeCsrf } from '@/shared/api/client';
import { useSettingsStore } from '@/shared/stores/settings';
import type { AuthUser, Branch } from '@/shared/types';

/**
 * Session and branch context.
 *
 * The selected branch is a view preference: it narrows what the screens ask
 * for. It grants nothing - the server decides what this user may see, and
 * rejects a branch that is not theirs.
 */
export const useAuthStore = defineStore('auth', () => {
    const user = ref<AuthUser | null>(null);
    const branches = ref<Branch[]>([]);
    const selectedBranchId = ref<string | null>(null);
    const ready = ref(false);

    const isSignedIn = computed(() => user.value !== null);
    const canSwitchBranch = computed(() => branches.value.length > 1);

    /**
     * Which application this person belongs in.
     *
     * A separate question from what they may do. A customer holds
     * view.subscription so they can look at their own plan, so deciding by
     * ability alone would drop them into the office's screens.
     */
    const isCustomer = computed(() => user.value?.role.value === 'customer');

    /** For hiding controls. Never a substitute for server authorisation. */
    function can(ability: string): boolean {
        return user.value?.abilities.includes('*') || user.value?.abilities.includes(ability) || false;
    }

    async function loadSession(): Promise<void> {
        try {
            const { data } = await api.get('/me');
            user.value = data.data;
            branches.value = data.branches;

            // Their theme and layout arrive with the session, so the interface
            // draws itself correctly on the first paint instead of flashing the
            // default and rearranging.
            useSettingsStore().hydrate(data.data?.settings);

            if (!selectedBranchId.value && branches.value.length === 1) {
                selectedBranchId.value = branches.value[0].id;
            }
        } catch {
            // A failure here just means "not signed in".
            user.value = null;
            branches.value = [];
        } finally {
            ready.value = true;
        }
    }

    async function signIn(email: string, password: string, remember = false): Promise<void> {
        await primeCsrf();
        await api.post('/login', { email, password, remember });
        await loadSession();
    }

    /**
     * Ask for a code by text.
     *
     * The reply is the same whether or not the number is on our books, so
     * nothing here can be used to find out who is a customer.
     */
    async function requestCode(phone: string): Promise<void> {
        await primeCsrf();
        await api.post('/login/code', { phone });
    }

    async function signInWithCode(phone: string, code: string): Promise<void> {
        await primeCsrf();
        await api.post('/login/code/verify', { phone, code });
        await loadSession();
    }

    async function signOut(): Promise<void> {
        try {
            await api.post('/logout');
        } finally {
            user.value = null;
            branches.value = [];
            selectedBranchId.value = null;
            // Back to the defaults: the next person to sign in on this machine
            // should not inherit the last one's theme.
            useSettingsStore().reset();
        }
    }

    function selectBranch(id: string | null): void {
        selectedBranchId.value = id;
    }

    return {
        user, branches, selectedBranchId, ready,
        isSignedIn, canSwitchBranch, isCustomer,
        can, loadSession, signIn, signOut, signInWithCode, requestCode, selectBranch,
    };
});
