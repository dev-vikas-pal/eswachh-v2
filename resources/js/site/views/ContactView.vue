<script setup lang="ts">
import { computed } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import { RouterLink } from 'vue-router';
import { api } from '@/shared/api/client';

/**
 * Details come from the settings screen, so the office can change the phone
 * number the day it changes rather than waiting for a release.
 */
const { data } = useQuery({
    queryKey: ['public-content'],
    queryFn: async () => (await api.get('/public/content')).data.data,
    staleTime: 5 * 60 * 1000,
});

const contact = computed(() => data.value?.contact ?? {});

/** Only offered when there is a number to offer. */
const whatsappLink = computed(() => {
    const raw = (contact.value.whatsapp ?? '').replace(/\D/g, '');
    if (!raw) return null;
    // Assume an Indian number when no country code was entered.
    return `https://wa.me/${raw.length === 10 ? '91' + raw : raw}`;
});
</script>

<template>
    <div class="mx-auto max-w-4xl px-4 py-10">
        <h1 class="text-2xl font-bold tracking-tight text-ink">Get in touch</h1>
        <p class="mt-1 max-w-prose text-body">
            The quickest way to sort anything out is to call the office. If it is about a clean that
            went wrong, raising it from your account gets it a reference number and a named person.
        </p>

        <div class="mt-8 grid gap-5 sm:grid-cols-2">
            <a
                v-if="contact.phone"
                :href="'tel:' + contact.phone"
                class="rounded-lg border border-line bg-surface p-5 transition hover:border-line-strong"
            >
                <h2 class="text-xs font-semibold uppercase tracking-wide text-muted">Phone</h2>
                <p class="mt-1 text-lg font-semibold tabular-nums text-ink">{{ contact.phone }}</p>
                <p v-if="contact.hours" class="mt-1 text-sm text-body">{{ contact.hours }}</p>
            </a>

            <a
                v-if="whatsappLink"
                :href="whatsappLink"
                target="_blank"
                rel="noopener"
                class="rounded-lg border border-line bg-surface p-5 transition hover:border-line-strong"
            >
                <h2 class="text-xs font-semibold uppercase tracking-wide text-muted">WhatsApp</h2>
                <p class="mt-1 text-lg font-semibold tabular-nums text-ink">{{ contact.whatsapp }}</p>
                <p class="mt-1 text-sm text-body">Send us a photo if something was missed.</p>
            </a>

            <a
                v-if="contact.email"
                :href="'mailto:' + contact.email"
                class="rounded-lg border border-line bg-surface p-5 transition hover:border-line-strong"
            >
                <h2 class="text-xs font-semibold uppercase tracking-wide text-muted">Email</h2>
                <p class="mt-1 text-lg font-semibold text-ink">{{ contact.email }}</p>
            </a>

            <div v-if="contact.address" class="rounded-lg border border-line bg-surface p-5">
                <h2 class="text-xs font-semibold uppercase tracking-wide text-muted">Office</h2>
                <p class="mt-1 whitespace-pre-line text-body">{{ contact.address }}</p>
            </div>
        </div>

        <p
            v-if="!contact.phone && !contact.email"
            class="mt-8 rounded border border-line bg-surface px-4 py-6 text-center text-muted"
        >
            Contact details have not been published yet.
        </p>

        <section class="mt-10 rounded-lg border border-line bg-sunk p-5">
            <h2 class="font-semibold text-ink">Already a customer?</h2>
            <p class="mt-1 text-sm text-body">
                Sign in to see your plan, your payments, and to raise a complaint that gets tracked
                rather than forgotten.
            </p>
            <div class="mt-4 flex flex-wrap gap-3">
                <a
                    href="/login"
                    class="rounded bg-accent px-5 py-2 text-sm font-semibold text-on-accent transition hover:brightness-110"
                >
                    Sign in
                </a>
                <RouterLink
                    :to="{ name: 'faq' }"
                    class="rounded border border-line-strong px-5 py-2 text-sm font-semibold text-body transition hover:bg-surface"
                >
                    Read the questions first
                </RouterLink>
            </div>
        </section>
    </div>
</template>
