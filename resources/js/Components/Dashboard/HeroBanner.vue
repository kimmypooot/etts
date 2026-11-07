<script setup>
/**
 * The banner at the top of a dashboard: a brand-colour banner over the CSC facade photo with a soft
 * dot pattern, a greeting, a line of context, and quick actions. Text is
 * white on the brand blue (well above AA contrast). The cards
 * below it pull up over its bottom edge (`-mt-10`) for depth.
 */
defineProps({
    eyebrow: { type: String, default: null },
    title: { type: String, required: true },
    subtitle: { type: String, default: null },
});
</script>

<template>
    <section class="hero-solid relative overflow-hidden rounded-2xl px-6 pt-7 pb-16 text-white shadow-card sm:px-8" aria-labelledby="hero-title">
        <!-- Decorative photo under a brand-blue tint, so the text keeps its contrast. -->
        <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('/images/cscbg_facade.jpeg')" aria-hidden="true" />
        <div class="absolute inset-0 bg-linear-to-br from-csc-blue/92 via-csc-blue/90 to-csc-blue-dark/95" aria-hidden="true" />

        <svg class="pointer-events-none absolute inset-0 size-full opacity-[0.07]" aria-hidden="true">
            <defs>
                <pattern id="hero-dots" width="22" height="22" patternUnits="userSpaceOnUse"><circle cx="2" cy="2" r="1.4" fill="white" /></pattern>
            </defs>
            <rect width="100%" height="100%" fill="url(#hero-dots)" />
        </svg>

        <div class="relative flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
            <div class="min-w-0">
                <p v-if="eyebrow" class="text-xs font-semibold tracking-[0.18em] text-white/75 uppercase">{{ eyebrow }}</p>
                <h1 id="hero-title" class="mt-1 text-2xl font-semibold tracking-tight sm:text-3xl">{{ title }}</h1>
                <p v-if="subtitle" class="mt-2 max-w-2xl text-sm text-white/85">{{ subtitle }}</p>
                <div v-if="$slots.default" class="mt-4 flex flex-wrap gap-2"><slot /></div>
            </div>
            <div v-if="$slots.actions" class="flex flex-wrap gap-2"><slot name="actions" /></div>
        </div>
    </section>
</template>
