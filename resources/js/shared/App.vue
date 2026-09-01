<script setup lang="ts">
import { RouterView } from 'vue-router';
import PaymentProgress from '@/shared/PaymentProgress.vue';
</script>

<template>
    <RouterView />

    <!--
        Mounted once, beside the router view, so every screen that can take
        money is covered without any of them asking for it - including ones
        written later. It draws nothing at all until a payment is in flight.
    -->
    <PaymentProgress />
</template>
