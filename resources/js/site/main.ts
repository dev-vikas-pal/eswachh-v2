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

/*
 * The site's two typefaces, bundled rather than fetched from a font service:
 * no third-party request on a visitor's first paint, and they load from the
 * same place as everything else. Imported here and not in app.css so the
 * office bundle, which shares that stylesheet, does not carry them.
 */
import '@fontsource-variable/fraunces/opsz.css';
import '@fontsource-variable/plus-jakarta-sans';

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
