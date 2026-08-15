<script setup lang="ts">
import { ref } from 'vue';
import { api, describeError, type ValidationErrors } from '@/shared/api/client';

/**
 * Changing your own password.
 *
 * v2 could set anybody's password from the staff screen and nobody's own, so
 * every password was one an administrator had chosen and seen. This is the
 * other half.
 */
defineEmits<{ (e: 'close'): void }>();

const form = ref({ current_password: '', password: '', password_confirmation: '' });
const busy = ref(false);
const done = ref(false);
const message = ref<string | null>(null);
const errors = ref<ValidationErrors>({});

async function save() {
    busy.value = true;
    message.value = null;
    errors.value = {};

    try {
        const { data } = await api.patch('/me/password', form.value);

        done.value = true;
        message.value = data.message;
        form.value = { current_password: '', password: '', password_confirmation: '' };
    } catch (e) {
        const described = describeError(e);
        message.value = described.message;
        errors.value = described.errors;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4" @click.self="$emit('close')">
        <form class="w-full max-w-sm rounded-lg border border-line-strong bg-surface p-5 shadow-xl" @submit.prevent="save">
            <h2 class="text-lg font-semibold text-ink">Change your password</h2>

            <p
                v-if="message"
                class="mt-3 rounded px-3 py-2 text-sm"
                :class="done ? 'bg-ok-soft text-ok' : 'bg-crit-soft text-crit'"
                role="alert"
            >
                {{ message }}
            </p>

            <label class="mt-4 block">
                <span class="mb-1 block text-sm font-medium text-body">Current password</span>
                <input
                    v-model="form.current_password"
                    type="password"
                    autocomplete="current-password"
                    required
                    class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                />
                <span v-if="errors.current_password" class="mt-1 block text-xs text-crit">{{ errors.current_password[0] }}</span>
            </label>

            <label class="mt-3 block">
                <span class="mb-1 block text-sm font-medium text-body">New password</span>
                <input
                    v-model="form.password"
                    type="password"
                    autocomplete="new-password"
                    required
                    class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                />
                <span v-if="errors.password" class="mt-1 block text-xs text-crit">{{ errors.password[0] }}</span>
            </label>

            <label class="mt-3 block">
                <span class="mb-1 block text-sm font-medium text-body">New password again</span>
                <input
                    v-model="form.password_confirmation"
                    type="password"
                    autocomplete="new-password"
                    required
                    class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                />
            </label>

            <p class="mt-3 text-xs text-faint">
                At least eight characters, and not one that has appeared in a known breach.
            </p>

            <div class="mt-4 flex gap-2">
                <button
                    type="submit"
                    :disabled="busy"
                    class="rounded bg-accent px-4 py-2 text-sm font-medium text-on-accent transition hover:brightness-110 disabled:opacity-60"
                >
                    {{ busy ? 'Saving…' : 'Change it' }}
                </button>

                <button
                    type="button"
                    class="rounded border border-line-strong px-4 py-2 text-sm text-body transition hover:bg-sunk"
                    @click="$emit('close')"
                >
                    {{ done ? 'Close' : 'Cancel' }}
                </button>
            </div>
        </form>
    </div>
</template>
