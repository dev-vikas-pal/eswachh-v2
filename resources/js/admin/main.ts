/**
 * The working application.
 *
 * Needs a session, and everything in it is behind one. Kept apart from the
 * public bundle so the marketing pages stay small.
 */
import { createApp } from 'vue';
import { createPinia } from 'pinia';
import { VueQueryPlugin } from '@tanstack/vue-query';
import router from '@/admin/router';
import App from '@/shared/App.vue';
import '../../css/app.css';

createApp(App)
    .use(createPinia())
    .use(router)
    .use(VueQueryPlugin, {
        queryClientConfig: {
            defaultOptions: {
                queries: {
                    // Screens are read often and change slowly; a short stale
                    // window avoids refetching on every navigation.
                    staleTime: 30_000,
                    // Refetching a 403 or 404 will not make it succeed.
                    retry: (failureCount: number, error: any) => {
                        const status = error?.response?.status;
                        if (status && status >= 400 && status < 500) return false;
                        return failureCount < 2;
                    },
                },
            },
        },
    })
    .mount('#app');
