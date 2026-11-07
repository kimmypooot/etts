<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import Icon from '../Components/UI/Icon.vue';
import SidebarNav from '../Components/Navigation/SidebarNav.vue';
import UserMenu from '../Components/Navigation/UserMenu.vue';
import AppSwitcher from '../Components/Navigation/AppSwitcher.vue';
import OfficeSwitcher from '../Components/Navigation/OfficeSwitcher.vue';

/**
 * The staff shell, after IMIS's AppShell in its admin variant: a fixed top
 * bar (menu toggle, logo, account menu) and a white sidebar that docks on
 * desktop (remembered per browser) and overlays on smaller screens.
 * Server flash messages show as toasts (DialogHost, mounted in app.js).
 */
// The legacy ETTS sidebar order: Dashboard, then Menu (Transactions with its
// Active / History / Deleted pages, Transmittals, Activity log), Statistics and
// Settings. Pages that are new in this version sit with their closest relative.
const navItems = [
    { label: 'Dashboard', href: '/dashboard', icon: 'home' },

    // Field office portal
    { heading: 'Management' },
    {
        group: 'Transactions',
        icon: 'clipboard',
        items: [
            { label: 'Active', href: '/fo/transactions', icon: 'clipboard', permission: 'fo.portal' },
            { label: 'History', href: '/fo/transactions?board=history', icon: 'clipboard', permission: 'fo.portal' },
            { label: 'Deleted', href: '/fo/deleted', icon: 'archive', permission: 'fo.portal' },
        ],
    },
    { label: 'Transmittals', href: '/fo/transmittals', icon: 'envelope', permission: 'fo.portal' },

    // ESD
    { heading: 'Menu' },
    {
        group: 'Transactions',
        icon: 'clipboard',
        items: [
            { label: 'Active', href: '/admin/transactions', icon: 'clipboard', permission: 'transactions.view' },
            { label: 'History', href: '/admin/transactions?board=history', icon: 'clipboard', permission: 'transactions.view' },
            { label: 'Deleted', href: '/admin/archive', icon: 'archive', permission: 'archive.view' },
        ],
    },
    { label: 'Transmittals', href: '/admin/transmittals', icon: 'envelope', permission: 'transmittals.view' },
    { label: 'Activity Log', href: '/admin/activity', icon: 'activity', permission: 'activity.view' },

    { heading: 'Statistics' },
    { label: 'Special Eligibility', href: '/admin/statistics', icon: 'chartBar', permission: 'transactions.view' },
    { label: 'Issuance & Authentication', href: '/admin/statistics?category=coe_auth', icon: 'documentText', permission: 'transactions.view' },
    { label: 'Duplicate Audit', href: '/admin/audit/duplicates', icon: 'shield', permission: 'transactions.view' },

    { heading: 'Settings' },
    { label: 'Eligibility Types', href: '/admin/eligibilities', icon: 'tag', permission: 'eligibilities.manage' },
    { label: 'Kiosks', href: '/admin/kiosks', icon: 'desktop', permission: 'kiosks.manage' },
    { label: 'Staff', href: '/admin/users', icon: 'users', permission: 'activity.view' },
    { label: 'Sync from Legacy', href: '/admin/legacy-sync', icon: 'refresh', permission: 'activity.view' },
];

const STORAGE_KEY = 'etts.sidebar-open';
const DESKTOP_QUERY = '(min-width: 1024px)';
// Not a CSS asset: served as is from public/.
const logo = '/images/csc-logo.png';

const page = usePage();
const sidebarOpen = ref(false);
const isDesktop = ref(false);
const menuButtonRef = ref(null);
const sidebarRef = ref(null);

const readPreference = () => {
    try {
        return window.localStorage.getItem(STORAGE_KEY) !== '0';
    } catch {
        return true;
    }
};

const savePreference = (open) => {
    try {
        window.localStorage.setItem(STORAGE_KEY, open ? '1' : '0');
    } catch {
        // Storage unavailable; the toggle still works for this page.
    }
};

const setSidebar = async (open) => {
    sidebarOpen.value = open;

    if (isDesktop.value) {
        savePreference(open);

        return;
    }

    await nextTick();
    if (open) {
        sidebarRef.value?.querySelector('a')?.focus();
    } else {
        menuButtonRef.value?.focus();
    }
};

const onKeydown = (event) => {
    if (event.key === 'Escape' && sidebarOpen.value && !isDesktop.value) {
        setSidebar(false);
    }
};

let mediaQuery;
let removeNavigateListener;

const onMediaChange = () => {
    isDesktop.value = mediaQuery.matches;
    sidebarOpen.value = isDesktop.value ? readPreference() : false;
};

onMounted(() => {
    mediaQuery = window.matchMedia(DESKTOP_QUERY);
    onMediaChange();
    mediaQuery.addEventListener('change', onMediaChange);
    removeNavigateListener = router.on('navigate', () => {
        if (!isDesktop.value) sidebarOpen.value = false;
    });
});

onBeforeUnmount(() => {
    mediaQuery?.removeEventListener('change', onMediaChange);
    removeNavigateListener?.();
});
</script>

<template>
    <div class="min-h-screen bg-surface-muted" @keydown="onKeydown">
        <a
            href="#main-content"
            class="sr-only z-50 rounded-md bg-surface px-4 py-2 text-sm font-medium text-primary shadow-sm focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:outline-none focus:ring-2 focus:ring-focus-ring"
        >
            Skip to main content
        </a>

        <header class="fixed inset-x-0 top-0 z-40 flex h-16 print:hidden items-center gap-2 border-b border-border bg-surface px-3 sm:px-4">
            <button
                ref="menuButtonRef"
                type="button"
                class="rounded-md p-2 text-text hover:bg-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                :aria-label="sidebarOpen ? 'Hide navigation menu' : 'Show navigation menu'"
                :title="sidebarOpen ? 'Hide menu' : 'Show menu'"
                aria-controls="app-sidebar"
                :aria-expanded="sidebarOpen"
                @click="setSidebar(!sidebarOpen)"
            >
                <Icon name="menu" size="md" />
            </button>

            <Link
                href='/dashboard'
                class="flex items-center gap-2 rounded-md pr-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
            >
                <img :src="logo" alt="CSC" class="h-9 w-auto" />
                <span class="leading-tight">
                    <span class="block text-lg font-semibold text-primary">ETTS</span>
                    <span class="hidden text-xs text-text-muted sm:block">ESD Transactions Tracking System</span>
                </span>
            </Link>

            <div class="ml-auto flex items-center gap-1">
                <OfficeSwitcher />
                <AppSwitcher />
                <UserMenu v-if="page.props.auth.user" :user="page.props.auth.user" />
            </div>
        </header>

        <div
            v-if="sidebarOpen && !isDesktop"
            class="fixed inset-0 top-16 z-30 bg-text/50"
            aria-hidden="true"
            @click="setSidebar(false)"
        />

        <aside
            id="app-sidebar"
            ref="sidebarRef"
            class="fixed bottom-0 left-0 top-16 z-30 flex w-64 print:hidden flex-col border-r border-border bg-surface text-text shadow-sm transition-transform duration-200 motion-reduce:transition-none"
            :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
            :role="isDesktop ? undefined : 'dialog'"
            :aria-modal="isDesktop ? undefined : sidebarOpen"
            aria-label="Navigation menu"
            :inert="!sidebarOpen"
        >
            <div class="min-h-0 flex-1 overflow-y-auto py-4">
                <SidebarNav :items="navItems" variant="admin" />
            </div>

            <footer class="shrink-0 border-t border-border px-6 py-3 text-xs leading-relaxed text-text-muted">
                <p class="font-medium">ETTS · ESD</p>
                <p>CSC Regional Office VIII</p>
            </footer>
        </aside>

        <div
            class="flex min-h-screen flex-col pt-16 print:pt-0 transition-[padding] duration-200 motion-reduce:transition-none"
            :class="{ 'lg:pl-64 print:!pl-0': sidebarOpen }"
        >
            <main id="main-content" tabindex="-1" class="flex-1 px-4 py-6 focus:outline-none sm:px-6 lg:px-8">
                <slot />
            </main>
        </div>
    </div>
</template>
