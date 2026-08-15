<script setup lang="ts">
import { LOGO } from '@/shared/branding';
import { computed, ref } from 'vue';
import { RouterLink, RouterView } from 'vue-router';
import { useAuthStore } from '@/shared/stores/auth';
import { useSettingsStore } from '@/shared/stores/settings';
import BranchSelector from '@/admin/components/BranchSelector.vue';
import SettingsMenu from '@/shared/SettingsMenu.vue';
import AlertsBell from '@/admin/components/AlertsBell.vue';
import AccountMenu from '@/shared/AccountMenu.vue';

const auth = useAuthStore();
const settings = useSettingsStore();

/** Open state for the sidebar on a narrow screen, where it cannot sit fixed. */
const drawerOpen = ref(false);

/** Navigation is filtered by ability, so people only see what they can use. */
const navigation = computed(() =>
    [
        { name: 'Dashboard', to: { name: 'dashboard' }, ability: 'view.dashboard', icon: 'grid' },
        { name: 'My round', to: { name: 'round' }, ability: 'view.round', icon: 'route' },
        { name: 'Subscriptions', to: { name: 'subscriptions' }, ability: 'view.subscription', icon: 'card' },
        { name: 'Customers', to: { name: 'customers' }, ability: 'view.customer', icon: 'contact' },
        { name: 'Payments', to: { name: 'payments' }, ability: 'view.payment', icon: 'rupee' },
        { name: 'Complaints', to: { name: 'complaints' }, ability: 'view.complaint', icon: 'flag' },
        { name: 'Coverage', to: { name: 'coverage' }, ability: 'view.attendance', icon: 'route' },
        { name: 'Messages', to: { name: 'reminders' }, ability: 'view.subscription', icon: 'chat' },
        { name: 'Reports', to: { name: 'reports' }, ability: 'view.report', icon: 'chart' },
        { name: 'People', to: { name: 'users' }, ability: 'view.staff', icon: 'people' },
        { name: 'Masters', to: { name: 'masters' }, ability: 'manage.master', icon: 'sliders' },
        { name: 'Blog', to: { name: 'blog-admin' }, ability: 'manage.master', icon: 'pen' },
        { name: 'Settings', to: { name: 'settings' }, ability: 'manage.master', icon: 'cog' },
        { name: 'Logs', to: { name: 'logs' }, ability: 'manage.master', icon: 'chat' },
        // Administrator only, and that is a role rather than an ability - see
        // RoleController for why managing roles is not itself grantable.
        { name: 'Roles', to: { name: 'roles' }, ability: 'manage.master', superAdmin: true, icon: 'people' },
    ].filter((item) => auth.can(item.ability) && (!item.superAdmin || auth.user?.role.value === 'super_admin')),
);

const sideways = computed(() => settings.menuPosition === 'left');

/** Icons only, on a wide screen. Ignored while the menu is across the top. */
const collapsed = computed(() => settings.sidebarCollapsed);

const ICONS: Record<string, string> = {
    grid: 'M3 3h6v6H3zM11 3h6v6h-6zM3 11h6v6H3zM11 11h6v6h-6z',
    card: 'M2 5.5h16v9H2zM2 8.5h16',
    rupee: 'M6 4h8M6 7.5h8M12 4c2.5 0 3.2 3.5 0 3.5H6l6 8.5',
    flag: 'M4.5 17V3.5h11l-2.5 4 2.5 4h-11',
    route: 'M5.5 3.5a2 2 0 100 4 2 2 0 000-4zM14.5 12.5a2 2 0 100 4 2 2 0 000-4zM5.5 7.5v3a4 4 0 004 4h5',
    sliders: 'M3 6h9M15 6h2M3 14h2M8 14h9M13 3.5v5M6 11.5v5',
    pen: 'M3 17h4l9.5-9.5a2 2 0 10-2.8-2.8L4 14v3zM12.5 5.5l2 2',
    cog: 'M10 12.5a2.5 2.5 0 100-5 2.5 2.5 0 000 5zM10 2.5v2M10 15.5v2M17.5 10h-2M4.5 10h-2M15.3 4.7l-1.4 1.4M6.1 13.9l-1.4 1.4M15.3 15.3l-1.4-1.4M6.1 6.1 4.7 4.7',
    chat: 'M3 5.5h14v9H9l-4 3v-3H3z',
    chart: 'M3 16.5V9M8 16.5V4.5M13 16.5v-5M18 16.5V7',
    contact: 'M10 9.5a2.5 2.5 0 100-5 2.5 2.5 0 000 5zM4.5 16.5c0-3 2.5-5 5.5-5s5.5 2 5.5 5',
    people: 'M7 9a2.5 2.5 0 100-5 2.5 2.5 0 000 5zM2.5 16.5c0-2.5 2-4 4.5-4s4.5 1.5 4.5 4M14 9.5a2 2 0 100-4M14.5 12.5c1.8.2 3 1.5 3 4',
};
</script>

<template>
    <div class="min-h-screen bg-ground" :class="sideways ? 'md:flex' : ''">
        <!--
            Down the side. Fixed on a wide screen; a drawer on a narrow one,
            because a permanent sidebar on a phone leaves no room for the work.
        -->
        <aside
            v-if="sideways"
            class="shrink-0 border-line bg-surface transition-[width] md:border-r"
            :class="[collapsed ? 'md:w-16' : 'md:w-56', drawerOpen ? 'border-b' : 'border-b md:border-b-0']"
        >
            <div class="flex items-center gap-2 px-4 py-3 md:py-4" :class="collapsed ? 'md:justify-center md:px-2' : ''">
                <img :src="LOGO" alt="Eswachh" class="h-8 w-auto shrink-0" :class="collapsed ? 'md:hidden' : ''" />

                <!--
                    Narrow it to icons on a wide screen. The choice is saved
                    against the account like the theme is, so somebody who works
                    all day in a narrow sidebar does not re-collapse it every
                    morning.
                -->
                <button
                    type="button"
                    class="ms-auto hidden shrink-0 rounded border border-line-strong p-1.5 text-body transition hover:bg-sunk md:block"
                    :class="collapsed ? 'md:ms-0' : ''"
                    :aria-expanded="!collapsed"
                    :title="collapsed ? 'Widen the menu' : 'Narrow the menu'"
                    @click="settings.set('sidebar', collapsed ? 'wide' : 'narrow')"
                >
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                        <path :d="collapsed ? 'm7 5 5 5-5 5' : 'm13 5-5 5 5 5'" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </button>

                <button
                    type="button"
                    class="ms-auto rounded border border-line-strong px-2 py-1 text-sm text-body md:hidden"
                    :aria-expanded="drawerOpen"
                    @click="drawerOpen = !drawerOpen"
                >
                    Menu
                </button>
            </div>

            <nav class="flex-col gap-0.5 px-2 pb-3 md:flex" :class="drawerOpen ? 'flex' : 'hidden'">
                <RouterLink
                    v-for="item in navigation"
                    :key="item.name"
                    :to="item.to"
                    class="flex items-center gap-2.5 rounded px-3 py-2 text-sm font-medium text-body transition hover:bg-sunk hover:text-ink"
                    :class="collapsed ? 'md:justify-center md:px-2' : ''"
                    active-class="bg-accent-soft text-accent-ink"
                    :title="collapsed ? item.name : undefined"
                    @click="drawerOpen = false"
                >
                    <svg class="h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <path :d="ICONS[item.icon]" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <!-- Hidden rather than removed, so the drawer on a phone
                         always reads as words even when the wide screen is
                         showing icons. -->
                    <span :class="collapsed ? 'md:hidden' : ''">{{ item.name }}</span>
                </RouterLink>
            </nav>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="border-b border-line bg-surface">
                <div
                    class="flex flex-wrap items-center gap-3 px-4 py-3"
                    :class="sideways ? '' : 'mx-auto max-w-7xl gap-6'"
                >
                    <!-- Across the top: the logo lives here instead. -->
                    <img v-if="!sideways" :src="LOGO" alt="Eswachh" class="h-8 w-auto shrink-0" />

                    <nav v-if="!sideways" class="flex flex-wrap gap-1">
                        <RouterLink
                            v-for="item in navigation"
                            :key="item.name"
                            :to="item.to"
                            class="rounded px-3 py-1.5 text-sm font-medium text-body transition hover:bg-sunk hover:text-ink"
                            active-class="bg-accent-soft text-accent-ink"
                        >
                            {{ item.name }}
                        </RouterLink>
                    </nav>

                    <!--
                        The right-hand group gets its own min-width-0 so the
                        branch selector shrinks before anything overlaps, and
                        a divider separates "who am I" from the controls rather
                        than letting the name butt up against the button.
                    -->
                    <div class="ms-auto flex min-w-0 items-center gap-2 sm:gap-3">
                        <BranchSelector />
                        <AlertsBell />
                        <SettingsMenu />

                        <div class="hidden h-8 w-px shrink-0 bg-line sm:block" aria-hidden="true"></div>

                        <!-- Name, password and sign out in one control. -->
                        <AccountMenu />
                    </div>
                </div>
            </header>

            <main class="flex-1 px-4 py-6" :class="sideways ? '' : 'mx-auto w-full max-w-7xl'">
                <RouterView />
            </main>
        </div>
    </div>
</template>
