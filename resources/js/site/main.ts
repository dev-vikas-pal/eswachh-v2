/**
 * The public website.
 *
 * A separate bundle from the admin application on purpose: a visitor reading
 * the price list should not download the masters screen, the reports or the
 * user management form. They share the CSS tokens and the API client and
 * nothing else.
 */
import { createApp } from 'vue';
import { VueQueryPlugin } from '@tanstack/vue-query';
import router from '@/site/router';
import PublicLayout from '@/site/PublicLayout.vue';
import '../../css/app.css';

createApp(PublicLayout)
    .use(router)
    .use(VueQueryPlugin, {
        queryClientConfig: {
            defaultOptions: {
                queries: {
                    // The price list and the banners change rarely; the public
                    // site can hold them for a few minutes.
                    staleTime: 5 * 60_000,
                    retry: 1,
                },
            },
        },
    })
    .mount('#site');
