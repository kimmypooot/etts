<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import KioskLayout from '@/Layouts/KioskLayout.vue';
import KButton from '@/Components/Kiosk/KButton.vue';
import KioskIcon from '@/Components/Kiosk/KioskIcon.vue';

/**
 * The confirmation: an animated tick, the tracking number on a ticket, what
 * happens next, and a countdown back to the menu so the next visitor never sees
 * this one's name. It is shown once; reloading it returns to the menu.
 */
const props = defineProps({
    receipt: { type: Object, required: true },
});

const page = usePage();
const base = computed(() => page.props.kiosk.base);
const total = page.props.kiosk.doneSeconds;
const left = ref(total);
let timer;

const circumference = 2 * Math.PI * 28;

const steps = computed(() =>
    props.receipt.spels
        ? ['Keep this number. It identifies your application.', 'Wait to be called by the Action Officer.', 'Bring a valid ID and your supporting documents.']
        : ['Show this number to the Action Officer.', 'Bring a valid ID and your supporting documents.', 'Pay the fee at the cashier and keep your official receipt.'],
);

onMounted(() => {
    timer = window.setInterval(() => {
        left.value -= 1;
        if (left.value <= 0) {
            clearInterval(timer);
            router.visit(base.value);
        }
    }, 1000);
});
onBeforeUnmount(() => clearInterval(timer));

const padded = computed(() => String(props.receipt.id).padStart(6, '0'));
</script>

<template>
    <Head title="Request received" />

    <KioskLayout>
        <div class="mx-auto max-w-2xl text-center">
            <svg viewBox="0 0 120 120" class="mx-auto size-36" aria-hidden="true">
                <circle cx="60" cy="60" r="54" fill="var(--color-success-bg)" />
                <circle cx="60" cy="60" r="54" fill="none" stroke="var(--color-success)" stroke-width="5" stroke-dasharray="340" stroke-dashoffset="340" stroke-linecap="round" class="done-ring" />
                <path d="m36 62 17 17 32-36" fill="none" stroke="var(--color-success)" stroke-width="8" stroke-linecap="round" stroke-linejoin="round" stroke-dasharray="90" stroke-dashoffset="90" class="done-tick" />
            </svg>

            <h1 class="chart-rise mt-6 text-5xl font-semibold tracking-tight text-text" style="animation-delay: 500ms">Thank you, {{ receipt.first_name }}!</h1>
            <p class="chart-rise mt-3 text-xl text-text-muted" style="animation-delay: 600ms">{{ receipt.spels ? 'Your ' + receipt.type : 'Your ' + receipt.type + ' request' }} has been received.</p>

            <!-- The ticket -->
            <div class="chart-rise relative mx-auto mt-9 max-w-md" style="animation-delay: 700ms">
                <div class="overflow-hidden rounded-3xl bg-white shadow-[0_30px_60px_-30px_rgb(42_51_143/0.55)]">
                    <div class="hero-solid px-8 py-4 text-sm font-semibold tracking-[0.25em] text-white uppercase">Your tracking number</div>
                    <div class="px-8 pt-7 pb-8">
                        <p class="font-mono text-6xl font-semibold tracking-[0.12em] text-primary tabular-nums">{{ padded }}</p>
                        <p class="mt-3 text-base text-text-muted">{{ receipt.office }}</p>
                    </div>
                </div>
                <span class="absolute top-[3.6rem] -left-3 size-6 rounded-full bg-[#f4f6fc]" aria-hidden="true" />
                <span class="absolute top-[3.6rem] -right-3 size-6 rounded-full bg-[#f4f6fc]" aria-hidden="true" />
            </div>

            <ol class="chart-rise mx-auto mt-9 max-w-md space-y-3 text-left" style="animation-delay: 800ms">
                <li v-for="(text, index) in steps" :key="text" class="flex items-start gap-4 rounded-2xl bg-white/75 p-4">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-csc-blue text-base font-semibold text-white">{{ index + 1 }}</span>
                    <span class="pt-1 text-lg text-text">{{ text }}</span>
                </li>
            </ol>

            <div class="chart-rise mt-10 flex flex-col items-center gap-4" style="animation-delay: 900ms">
                <KButton :href="base">
                    <KioskIcon name="arrowRight" :size="22" /> Start a new request
                </KButton>
                <p class="flex items-center gap-3 text-base text-text-muted">
                    <svg viewBox="0 0 64 64" class="size-10 -rotate-90" aria-hidden="true">
                        <circle cx="32" cy="32" r="28" fill="none" stroke="var(--color-border)" stroke-width="5" />
                        <circle cx="32" cy="32" r="28" fill="none" stroke="var(--color-csc-blue)" stroke-width="5" stroke-linecap="round" :stroke-dasharray="circumference" :stroke-dashoffset="circumference * (1 - left / total)" class="transition-[stroke-dashoffset] duration-1000 ease-linear" />
                    </svg>
                    Returning to the start in {{ left }} seconds
                </p>
            </div>
        </div>
    </KioskLayout>
</template>

<style>
@keyframes done-draw {
    to {
        stroke-dashoffset: 0;
    }
}

.done-ring {
    animation: done-draw 700ms ease-out 100ms forwards;
}

.done-tick {
    animation: done-draw 500ms ease-out 650ms forwards;
}

@media (prefers-reduced-motion: reduce) {
    .done-ring,
    .done-tick {
        animation: none;
        stroke-dashoffset: 0;
    }
}
</style>
