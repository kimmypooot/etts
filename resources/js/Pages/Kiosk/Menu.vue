<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import KioskLayout from '@/Layouts/KioskLayout.vue';
import KioskIcon from '@/Components/Kiosk/KioskIcon.vue';

/**
 * The kiosk's home: a warm welcome and four large cards, one for each thing a
 * visitor can ask for. Each card is one big link (a generous touch target) with
 * its own colour and icon. Cards rise in one after another.
 */
const props = defineProps({
    types: { type: Array, required: true },
});

const page = usePage();
const base = computed(() => page.props.kiosk.base);
const terminal = computed(() => page.props.terminal);

const meta = {
    certification: {
        icon: 'certificate',
        title: 'Certification',
        text: 'Request a copy of your Certificate of Eligibility (CoE).',
        tint: 'bg-csc-blue',
        soft: 'bg-info-bg text-primary',
    },
    authentication: {
        icon: 'authenticate',
        title: 'Authentication',
        text: 'Have your Certificate of Eligibility authenticated.',
        tint: 'bg-[#5b3fc0]',
        soft: 'bg-[#efeaff] text-[#5b3fc0]',
    },
    'certification-and-authentication': {
        icon: 'both',
        title: 'Certification and Authentication',
        text: 'Request both at the same time and save a trip.',
        tint: 'bg-[#c9501b]',
        soft: 'bg-[#fff0e6] text-[#c9501b]',
    },
    'special-eligibility': {
        icon: 'graduate',
        title: 'Special Eligibility Application',
        text: 'Apply for an eligibility such as Honor Graduate, Barangay Official or Skills.',
        tint: 'bg-[#0d7c7a]',
        soft: 'bg-[#e4f6f5] text-[#0d7c7a]',
    },
};

const cards = computed(() => props.types.map((item) => ({ ...item, ...meta[item.slug] })));
</script>

<template>
    <Head title="Welcome" />

    <KioskLayout wide>
        <section class="chart-rise pt-2 text-center sm:pt-6">
            <p class="inline-flex items-center gap-2 rounded-full bg-white/80 px-4 py-1.5 text-sm font-medium text-primary shadow-sm">
                <KioskIcon name="sparkle" :size="16" /> {{ terminal?.office }}
            </p>
            <h1 class="mt-5 text-5xl font-semibold tracking-tight text-text sm:text-6xl">Welcome!</h1>
            <p class="mx-auto mt-4 max-w-2xl text-xl text-text-muted">What would you like to do today? Tap a card to begin. It takes about five minutes.</p>
        </section>

        <ul class="mx-auto mt-10 grid max-w-5xl gap-5 sm:mt-12 sm:grid-cols-2 sm:gap-6">
            <li v-for="(card, index) in cards" :key="card.slug" class="chart-rise" :style="{ animationDelay: `${120 + index * 90}ms` }">
                <Link
                    :href="`${base}/request/${card.slug}`"
                    class="group relative flex h-full min-h-48 flex-col overflow-hidden rounded-[1.75rem] border border-white bg-white/90 p-7 shadow-[0_20px_45px_-28px_rgb(42_51_143/0.55)] backdrop-blur transition duration-200 hover:-translate-y-1 hover:shadow-[0_28px_55px_-26px_rgb(42_51_143/0.65)] focus:outline-none focus-visible:ring-4 focus-visible:ring-csc-blue-light/50 active:scale-[0.985] sm:p-8"
                >
                    <span class="pointer-events-none absolute -top-16 -right-16 size-44 rounded-full opacity-[0.12] transition duration-300 group-hover:scale-125 group-hover:opacity-20" :class="card.tint" aria-hidden="true" />
                    <span class="relative flex size-16 items-center justify-center rounded-2xl text-white shadow-lg transition duration-200 group-hover:scale-105 group-hover:-rotate-3" :class="card.tint">
                        <KioskIcon :name="card.icon" :size="32" />
                    </span>
                    <h2 class="relative mt-5 text-2xl font-semibold leading-snug text-text">{{ card.title }}</h2>
                    <p class="relative mt-2 flex-1 text-lg leading-relaxed text-text-muted">{{ card.text }}</p>
                    <span class="relative mt-5 inline-flex items-center gap-2 self-start rounded-full px-4 py-2 text-base font-semibold transition-all duration-200 group-hover:gap-3.5" :class="card.soft">
                        Start <KioskIcon name="arrowRight" :size="20" />
                    </span>
                </Link>
            </li>
        </ul>

        <section class="chart-rise mx-auto mt-12 max-w-5xl" style="animation-delay: 520ms" aria-labelledby="how">
            <h2 id="how" class="mb-4 text-center text-sm font-semibold tracking-[0.2em] text-text-muted uppercase">How it works</h2>
            <ol class="grid gap-4 sm:grid-cols-3">
                <li class="flex items-start gap-4 rounded-2xl bg-white/60 p-5"><span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-csc-blue text-lg font-semibold text-white">1</span><span><strong class="block text-lg font-semibold text-text">Choose</strong><span class="text-base text-text-muted">Pick the service you need.</span></span></li>
                <li class="flex items-start gap-4 rounded-2xl bg-white/60 p-5"><span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-csc-blue text-lg font-semibold text-white">2</span><span><strong class="block text-lg font-semibold text-text">Fill in</strong><span class="text-base text-text-muted">Answer a few short questions.</span></span></li>
                <li class="flex items-start gap-4 rounded-2xl bg-white/60 p-5"><span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-csc-blue text-lg font-semibold text-white">3</span><span><strong class="block text-lg font-semibold text-text">Get your number</strong><span class="text-base text-text-muted">Show it to the Action Officer.</span></span></li>
            </ol>
        </section>
    </KioskLayout>
</template>
