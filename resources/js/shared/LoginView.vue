<script setup lang="ts">
import { computed, ref } from 'vue';
import { LOGO } from '@/shared/branding';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '@/shared/stores/auth';
import { describeError, type ValidationErrors } from '@/shared/api/client';

/**
 * Two ways in, because there are two kinds of person signing in here.
 *
 * Staff have an email and a password. Customers were imported from v1 with the
 * hash of a password they have never typed, and v1 signed them in with a code
 * sent to their mobile - so that stays. Asking them for a password instead
 * would lock out the entire customer base on the day this goes live.
 */
const auth = useAuthStore();
const router = useRouter();
const route = useRoute();

type Method = 'password' | 'code';
const method = ref<Method>('password');

const email = ref('');
const password = ref('');
const remember = ref(false);

const phone = ref('');
const code = ref('');
const codeSent = ref(false);

const busy = ref(false);
const message = ref('');
const notice = ref('');
const errors = ref<ValidationErrors>({});

/** Where this person belongs, decided after the session is loaded. */
const destination = computed(() =>
    (route.query.next as string) || (auth.isCustomer ? { name: 'portal-home' } : { name: 'dashboard' }),
);

function switchTo(next: Method) {
    method.value = next;
    message.value = '';
    notice.value = '';
    errors.value = {};
}

async function run(work: () => Promise<void>) {
    busy.value = true;
    message.value = '';
    errors.value = {};

    try {
        await work();
    } catch (error) {
        const described = describeError(error);
        message.value = described.message;
        errors.value = described.errors;
    } finally {
        busy.value = false;
    }
}

const submit = () => run(async () => {
    await auth.signIn(email.value, password.value, remember.value);
    await router.push(destination.value);
});

const sendCode = () => run(async () => {
    await auth.requestCode(phone.value);
    codeSent.value = true;
    // Worded so it is true whether or not the number is on our books: the
    // form must not be usable to find out who is a customer.
    notice.value = 'If that number is on our books, a code is on its way. It lasts five minutes.';
});

const signInWithCode = () => run(async () => {
    await auth.signInWithCode(phone.value, code.value);
    await router.push(destination.value);
});
</script>

<template>
    <div class="min-h-screen grid place-items-center bg-ground px-4">
        <div class="w-full max-w-sm">
            <div class="mb-8 flex flex-col items-center text-center">
                <a href="/" title="Back to the website">
                    <img :src="LOGO" alt="Eswachh" class="h-14 w-auto" />
                </a>
                <p class="mt-3 text-sm text-muted">Sign in to continue</p>
            </div>

            <div class="rounded-lg border border-line bg-surface p-6 shadow-sm">
                <div class="mb-5 flex rounded border border-line-strong p-0.5 text-sm">
                    <button
                        type="button"
                        class="flex-1 rounded px-3 py-1.5 transition"
                        :class="method === 'password' ? 'bg-accent font-medium text-on-accent' : 'text-body hover:bg-sunk'"
                        @click="switchTo('password')"
                    >
                        Email &amp; password
                    </button>
                    <button
                        type="button"
                        class="flex-1 rounded px-3 py-1.5 transition"
                        :class="method === 'code' ? 'bg-accent font-medium text-on-accent' : 'text-body hover:bg-sunk'"
                        @click="switchTo('code')"
                    >
                        Code by text
                    </button>
                </div>

                <!-- One message for a wrong address and a wrong password: telling
                     them apart would reveal which addresses exist. -->
                <p
                    v-if="message"
                    class="mb-4 rounded border border-crit bg-crit-soft px-3 py-2 text-sm text-crit"
                    role="alert"
                >
                    {{ message }}
                </p>

                <p v-else-if="notice" class="mb-4 rounded border border-ok-soft bg-ok-soft px-3 py-2 text-sm text-ok">
                    {{ notice }}
                </p>

                <form v-if="method === 'password'" novalidate @submit.prevent="submit">
                    <label class="mb-4 block">
                        <span class="mb-1 block text-sm font-medium text-body">Email</span>
                        <input
                            v-model="email"
                            type="email"
                            autocomplete="username"
                            required
                            class="w-full rounded border border-line-strong px-3 py-2 text-sm focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                        />
                        <span v-if="errors.email" class="mt-1 block text-xs text-crit">{{ errors.email[0] }}</span>
                    </label>

                    <label class="mb-4 block">
                        <span class="mb-1 block text-sm font-medium text-body">Password</span>
                        <input
                            v-model="password"
                            type="password"
                            autocomplete="current-password"
                            required
                            class="w-full rounded border border-line-strong px-3 py-2 text-sm focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                        />
                        <span v-if="errors.password" class="mt-1 block text-xs text-crit">{{ errors.password[0] }}</span>
                    </label>

                    <label class="mb-5 flex items-center gap-2 text-sm text-body">
                        <input v-model="remember" type="checkbox" class="rounded border-line-strong" />
                        Keep me signed in
                    </label>

                    <button
                        type="submit"
                        :disabled="busy"
                        class="w-full rounded bg-accent px-4 py-2 text-sm font-semibold text-on-accent transition hover:brightness-110 focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2 disabled:opacity-60"
                    >
                        {{ busy ? 'Signing in…' : 'Sign in' }}
                    </button>
                </form>

                <form v-else novalidate @submit.prevent="codeSent ? signInWithCode() : sendCode()">
                    <label class="mb-4 block">
                        <span class="mb-1 block text-sm font-medium text-body">Mobile number</span>
                        <input
                            v-model.trim="phone"
                            type="tel"
                            inputmode="numeric"
                            autocomplete="tel"
                            required
                            :disabled="codeSent"
                            class="w-full rounded border border-line-strong px-3 py-2 text-sm tabular-nums focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent disabled:opacity-60"
                        />
                        <span v-if="errors.phone" class="mt-1 block text-xs text-crit">{{ errors.phone[0] }}</span>
                    </label>

                    <label v-if="codeSent" class="mb-5 block">
                        <span class="mb-1 block text-sm font-medium text-body">Six digit code</span>
                        <input
                            v-model.trim="code"
                            type="text"
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            maxlength="6"
                            required
                            class="w-full rounded border border-line-strong px-3 py-2 text-center text-lg tracking-[0.4em] tabular-nums focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                        />
                        <span v-if="errors.code" class="mt-1 block text-xs text-crit">{{ errors.code[0] }}</span>
                    </label>

                    <button
                        type="submit"
                        :disabled="busy"
                        class="w-full rounded bg-accent px-4 py-2 text-sm font-semibold text-on-accent transition hover:brightness-110 focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2 disabled:opacity-60"
                    >
                        {{ busy ? 'Please wait…' : codeSent ? 'Sign in' : 'Send me a code' }}
                    </button>

                    <button
                        v-if="codeSent"
                        type="button"
                        class="mt-3 w-full text-center text-xs text-muted underline hover:text-ink"
                        @click="codeSent = false; code = ''; notice = ''"
                    >
                        Use a different number
                    </button>
                </form>
            </div>
        </div>
    </div>
</template>
