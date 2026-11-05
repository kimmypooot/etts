<script setup>
import { computed, ref } from 'vue';
import { formatNumber, seriesColors, toneColors } from './palette.js';

/**
 * A donut for a handful of parts of a whole, with the total in the middle, a
 * legend that doubles as the text equivalent, and a hover read-out. A segment's
 * `color` may be a tone name (success, warning, danger, info, ...) or a CSS
 * colour.
 */
const props = defineProps({
    segments: { type: Array, required: true }, // [{ label, value, color? }]
    centerLabel: { type: String, default: 'Total' },
    size: { type: Number, default: 188 },
    thickness: { type: Number, default: 26 },
    label: { type: String, default: 'Breakdown' },
});

const hovered = ref(null);

const colorOf = (segment, i) => toneColors[segment.color] ?? segment.color ?? seriesColors[i % seriesColors.length];
const total = computed(() => props.segments.reduce((sum, s) => sum + s.value, 0));
const radius = computed(() => (props.size - props.thickness) / 2);
const circumference = computed(() => 2 * Math.PI * radius.value);
const gap = 3;

const arcs = computed(() => {
    let offset = 0;

    return props.segments.map((segment, i) => {
        const length = total.value ? (segment.value / total.value) * circumference.value : 0;
        const arc = {
            ...segment,
            color: colorOf(segment, i),
            dash: `${Math.max(0, length - (props.segments.filter((s) => s.value > 0).length > 1 ? gap : 0))} ${circumference.value}`,
            offset: -offset,
            percent: total.value ? Math.round((segment.value / total.value) * 100) : 0,
        };
        offset += length;

        return arc;
    });
});

const center = computed(() => (hovered.value === null ? { value: total.value, text: props.centerLabel } : { value: arcs.value[hovered.value].value, text: arcs.value[hovered.value].label }));
</script>

<template>
    <div class="flex flex-col items-center gap-5">
        <div class="relative shrink-0" :style="{ width: `${size}px`, height: `${size}px` }">
            <svg :width="size" :height="size" :viewBox="`0 0 ${size} ${size}`" aria-hidden="true" class="-rotate-90">
                <circle :cx="size / 2" :cy="size / 2" :r="radius" fill="none" stroke="var(--color-surface-muted)" :stroke-width="thickness" />
                <circle
                    v-for="(arc, i) in arcs"
                    :key="arc.label"
                    :cx="size / 2"
                    :cy="size / 2"
                    :r="radius"
                    fill="none"
                    :stroke="arc.color"
                    :stroke-width="hovered === i ? thickness + 5 : thickness"
                    :stroke-dasharray="arc.dash"
                    :stroke-dashoffset="arc.offset"
                    stroke-linecap="butt"
                    class="cursor-default transition-[stroke-width] duration-150"
                    @pointerenter="hovered = i"
                    @pointerleave="hovered = null"
                />
            </svg>
            <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center px-6 text-center">
                <span class="text-2xl font-bold text-text tabular-nums">{{ formatNumber(center.value) }}</span>
                <span class="mt-0.5 text-[11px] leading-tight text-text-muted">{{ center.text }}</span>
            </div>
        </div>

        <ul class="w-full min-w-0 flex-1 space-y-1.5 text-sm" :aria-label="label">
            <li
                v-for="(arc, i) in arcs"
                :key="arc.label"
                class="flex items-center justify-between gap-3 rounded-md px-2 py-1 transition-colors"
                :class="hovered === i ? 'bg-surface-muted' : ''"
                @pointerenter="hovered = i"
                @pointerleave="hovered = null"
            >
                <span class="inline-flex min-w-0 items-center gap-2 text-text-muted">
                    <span class="size-2.5 shrink-0 rounded-full" :style="{ background: arc.color }" />
                    <span class="truncate">{{ arc.label }}</span>
                </span>
                <span class="shrink-0 tabular-nums">
                    <strong class="text-text">{{ formatNumber(arc.value) }}</strong>
                    <span class="ml-1.5 text-xs text-text-muted">{{ arc.percent }}%</span>
                </span>
            </li>
        </ul>
    </div>
</template>
