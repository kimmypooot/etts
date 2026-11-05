<script setup>
import { useId } from 'vue';

/**
 * A raised card for a chart or list: a title (with a short description), room
 * for a control on the right, the content, and an optional footer note. The
 * title labels the region for assistive technology.
 */
defineProps({
    title: { type: String, required: true },
    description: { type: String, default: null },
});

const id = useId();
</script>

<template>
    <section class="surface-raised flex h-full flex-col p-5" :aria-labelledby="`card-${id}`">
        <header class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 :id="`card-${id}`" class="text-base font-semibold text-text">{{ title }}</h2>
                <p v-if="description" class="mt-0.5 text-sm text-text-muted">{{ description }}</p>
            </div>
            <slot name="actions" />
        </header>
        <div class="min-h-0 flex-1"><slot /></div>
        <footer v-if="$slots.footer" class="mt-4 border-t border-border pt-3 text-xs text-text-muted"><slot name="footer" /></footer>
    </section>
</template>
