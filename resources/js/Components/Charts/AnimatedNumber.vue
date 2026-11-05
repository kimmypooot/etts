<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { formatNumber, formatPeso, prefersReducedMotion } from './palette.js';

/**
 * A figure that counts up to its value the first time it appears and between
 * changes. With reduced motion, or no animation frame (print, tests), it simply
 * shows the value. The final text is always the exact value.
 */
const props = defineProps({
    value: { type: Number, default: 0 },
    format: { type: String, default: 'number' }, // number | peso | decimal
    duration: { type: Number, default: 700 },
});

const shown = ref(props.value);
let frame = null;

const render = computed(() => {
    if (props.format === 'peso') return formatPeso(shown.value);
    if (props.format === 'decimal') return Number(shown.value).toLocaleString('en-PH', { minimumFractionDigits: 1, maximumFractionDigits: 1 });

    return formatNumber(shown.value);
});

const animate = (from, to) => {
    cancelAnimationFrame(frame);

    if (prefersReducedMotion() || typeof requestAnimationFrame === 'undefined' || from === to) {
        shown.value = to;

        return;
    }

    const start = performance.now();
    const tick = (now) => {
        const progress = Math.min(1, (now - start) / props.duration);
        const eased = 1 - (1 - progress) ** 3;
        shown.value = props.format === 'decimal' ? from + (to - from) * eased : Math.round(from + (to - from) * eased);

        if (progress < 1) frame = requestAnimationFrame(tick);
    };

    frame = requestAnimationFrame(tick);
};

onMounted(() => animate(0, props.value));
watch(() => props.value, (to, from) => animate(from, to));
onBeforeUnmount(() => cancelAnimationFrame(frame));
</script>

<template>
    <span class="tabular-nums">{{ render }}</span>
</template>
