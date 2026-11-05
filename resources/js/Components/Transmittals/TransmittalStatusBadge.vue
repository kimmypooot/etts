<script setup>
import { computed } from 'vue';
import { toneClasses } from '../UI/tones';

/** A transmittal batch status as a pill: Draft is open, Released and Transmitted are done. */
const props = defineProps({
    status: { type: String, required: true },
});

const tones = { Draft: 'warning', Released: 'success', Transmitted: 'success' };
const classes = computed(() => toneClasses[tones[props.status] ?? 'neutral']);
</script>

<template>
    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap" :class="classes">
        <span class="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true" />
        {{ status }}
    </span>
</template>
