<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import KioskIcon from '../Components/Kiosk/KioskIcon.vue';

/**
 * The frame of every kiosk screen: a soft brand backdrop, a header with the
 * logo, the office and a live clock, and a quiet footer. Content is centred in a
 * wide column sized for a touch screen. When the form token has expired
 * (a terminal left alone overnight) the page simply reloads instead of showing
 * an error to a member of the public.
 */
defineProps({
    wide: { type: Boolean, default: false },
});

// Served from public/, not bundled.
const logo = '/images/csc-logo.png';

const page = usePage();
const now = ref(new Date());
let timer;

const time = () => now.value.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit', timeZone: 'Asia/Manila' });
const date = () => now.value.toLocaleDateString('en-PH', { weekday: 'long', month: 'long', day: 'numeric', timeZone: 'Asia/Manila' });

let removeInvalid;
onMounted(() => {
    timer = window.setInterval(() => (now.value = new Date()), 15000);
    removeInvalid = router.on('invalid', (event) => {
        event.preventDefault();
        window.location.reload();
    });
});
onBeforeUnmount(() => {
    clearInterval(timer);
    removeInvalid?.();
});
</script>

<template>
    <div class="relative flex min-h-dvh flex-col overflow-hidden bg-surface-muted">
        <header class="relative z-10 border-b border-border bg-white">
            <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-5 py-3 sm:px-8">
                <div class="flex items-center gap-3.5">
                    <img :src="logo" alt="" class="h-12 w-auto" draggable="false" />
                    <div class="leading-tight">
                        <p class="text-base font-semibold text-primary sm:text-lg">Civil Service Commission</p>
                        <p class="text-xs text-text-muted sm:text-sm">Regional Office VIII · Examination Services</p>
                    </div>
                </div>
                <div class="flex items-center gap-5 text-right">
                    <div v-if="page.props.terminal" class="hidden items-center gap-2 rounded-full bg-success-bg px-3.5 py-1.5 text-sm font-medium text-success sm:flex">
                        <span class="relative flex size-2.5"><span class="absolute inline-flex size-full animate-ping rounded-full bg-success opacity-60" /><span class="relative inline-flex size-2.5 rounded-full bg-success" /></span>
                        {{ page.props.terminal.office_code }}
                    </div>
                    <div class="leading-tight">
                        <p class="text-xl font-semibold text-text tabular-nums sm:text-2xl">{{ time() }}</p>
                        <p class="text-xs text-text-muted sm:text-sm">{{ date() }}</p>
                    </div>
                </div>
            </div>
        </header>

        <main class="relative z-10 mx-auto w-full flex-1 px-5 py-8 sm:px-8 sm:py-10" :class="wide ? 'max-w-7xl' : 'max-w-4xl'">
            <slot />
        </main>

        <footer class="relative z-10 px-5 pb-5 text-center text-xs text-text-muted sm:text-sm">
            <p class="inline-flex items-center gap-1.5"><KioskIcon name="lock" :size="14" /> Your information is used only to process your request, as provided by the Data Privacy Act of 2012.</p>
            <p v-if="page.props.terminal" class="mt-1 opacity-70">{{ page.props.terminal.name }}</p>
        </footer>
    </div>
</template>
