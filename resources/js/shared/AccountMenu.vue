<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '@/shared/stores/auth';
import ChangePasswordPanel from '@/shared/ChangePasswordPanel.vue';

/**
 * Who you are, and the two things you can do about it.
 *
 * One control rather than a name sitting next to a Sign out button: the name
 * was not clickable, which meant "change my password" had nowhere obvious to
 * live and ended up hidden in the appearance menu, where nobody found it.
 */
const auth = useAuthStore();
const router = useRouter();

const open = ref(false);
const changingPassword = ref(false);
const root = ref<HTMLElement | null>(null);

/** Initials, for the narrow screen where the name does not fit. */
function initials(name: string | undefined): string {
    if (!name) return '?';

    return name.trim().split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
}

function onPointer(event: MouseEvent) {
    if (root.value && !root.value.contains(event.target as Node)) open.value = false;
}

function onKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape') open.value = false;
}

onMounted(() => {
    document.addEventListener('mousedown', onPointer);
    document.addEventListener('keydown', onKeydown);
});

onBeforeUnmount(() => {
    document.removeEventListener('mousedown', onPointer);
    document.removeEventListener('keydown', onKeydown);
});

async function signOut() {
    open.value = false;
    await auth.signOut();
    await router.push({ name: 'login' });
}
</script>

<template>
    <div ref="root" class="relative shrink-0">
        <button
            type="button"
            class="flex items-center gap-2 rounded border border-line-strong px-2 py-1.5 text-left transition hover:bg-sunk focus:outline-none focus:ring-2 focus:ring-accent"
            :aria-expanded="open"
            aria-haspopup="true"
            @click="open = !open"
        >
            <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-accent text-xs font-semibold text-on-accent">
                {{ initials(auth.user?.name) }}
            </span>

            <span class="hidden min-w-0 leading-tight sm:block">
                <span class="block truncate text-sm font-medium text-ink">{{ auth.user?.name }}</span>
                <span class="block truncate text-xs text-muted">{{ auth.user?.role.label }}</span>
            </span>

            <svg class="h-4 w-4 shrink-0 text-muted" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                <path d="m5 7.5 5 5 5-5" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </button>

        <div
            v-if="open"
            class="absolute right-0 z-40 mt-1 w-56 rounded-lg border border-line-strong bg-surface p-1.5 shadow-lg"
            role="menu"
        >
            <!-- On a narrow screen the button shows only initials, so the name
                 has to appear somewhere once the menu is open. -->
            <div class="border-b border-line px-3 py-2 sm:hidden">
                <p class="truncate text-sm font-medium text-ink">{{ auth.user?.name }}</p>
                <p class="truncate text-xs text-muted">{{ auth.user?.role.label }}</p>
            </div>

            <button
                type="button"
                class="block w-full rounded px-3 py-2 text-left text-sm text-body transition hover:bg-sunk"
                @click="changingPassword = true; open = false"
            >
                Change my password
            </button>

            <button
                type="button"
                class="block w-full rounded px-3 py-2 text-left text-sm text-body transition hover:bg-sunk"
                @click="signOut"
            >
                Sign out
            </button>
        </div>

        <ChangePasswordPanel v-if="changingPassword" @close="changingPassword = false" />
    </div>
</template>
