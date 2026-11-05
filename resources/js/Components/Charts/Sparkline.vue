<script setup>
import { computed, useId } from 'vue';

/**
 * A tiny trend line with a soft fill, for the corner of a stat card. It is
 * decorative (aria-hidden): the figure it sits beside carries the meaning.
 */
const props = defineProps({
    values: { type: Array, required: true },
    color: { type: String, default: 'var(--color-csc-blue-light)' },
    width: { type: Number, default: 96 },
    height: { type: Number, default: 34 },
});

const id = useId();

const points = computed(() => {
    const max = Math.max(...props.values, 1);
    const step = props.values.length > 1 ? props.width / (props.values.length - 1) : 0;

    return props.values.map((value, i) => [i * step, props.height - 3 - (value / max) * (props.height - 8)]);
});

const line = computed(() => points.value.map(([x, y], i) => `${i === 0 ? 'M' : 'L'}${x.toFixed(1)},${y.toFixed(1)}`).join(' '));
const area = computed(() => `${line.value} L${props.width},${props.height} L0,${props.height} Z`);
const last = computed(() => points.value[points.value.length - 1]);
</script>

<template>
    <svg :width="width" :height="height" :viewBox="`0 0 ${width} ${height}`" aria-hidden="true" class="overflow-visible">
        <defs>
            <linearGradient :id="`spark-${id}`" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" :stop-color="color" stop-opacity="0.28" />
                <stop offset="100%" :stop-color="color" stop-opacity="0" />
            </linearGradient>
        </defs>
        <path :d="area" :fill="`url(#spark-${id})`" />
        <path :d="line" fill="none" :stroke="color" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="chart-sweep" />
        <circle v-if="last" :cx="last[0]" :cy="last[1]" r="3" :fill="color" stroke="white" stroke-width="1.5" />
    </svg>
</template>
