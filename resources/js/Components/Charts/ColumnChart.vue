<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { formatCompact, formatNumber, niceMax, seriesColors } from './palette.js';

/**
 * Columns for a few groups, one bar per series (grouped) or one stack per
 * group (stacked). Hovering or arrowing over a group reads out all its values;
 * clicking a bar reports which group and series it was, for drill-down.
 * Plain SVG, sized to its container, with a text table for screen readers.
 */
const props = defineProps({
    labels: { type: Array, required: true },
    series: { type: Array, required: true }, // [{ name, values, color? }]
    stacked: { type: Boolean, default: false },
    height: { type: Number, default: 280 },
    formatValue: { type: Function, default: formatNumber },
    label: { type: String, default: 'Comparison' },
});

const emit = defineEmits(['select']);

const box = ref(null);
const width = ref(640);
const active = ref(null);
let observer;

const pad = { top: 14, right: 14, bottom: 46, left: 42 };
const plotW = computed(() => Math.max(10, width.value - pad.left - pad.right));
const plotH = computed(() => props.height - pad.top - pad.bottom);
const groups = computed(() => props.labels.length);
const colorOf = (series, i) => series.color ?? seriesColors[i % seriesColors.length];

const yMax = computed(() => {
    const totals = props.labels.map((_, g) => (props.stacked ? props.series.reduce((sum, s) => sum + (s.values[g] ?? 0), 0) : Math.max(0, ...props.series.map((s) => s.values[g] ?? 0))));

    return niceMax(Math.max(0, ...totals));
});

const yAt = (value) => pad.top + plotH.value - (value / yMax.value) * plotH.value;
const yTicks = computed(() => [0, 1, 2, 3, 4].map((t) => ({ value: (yMax.value * t) / 4, y: yAt((yMax.value * t) / 4) })));

const slot = computed(() => plotW.value / Math.max(1, groups.value));
const barWidth = computed(() => {
    const inner = slot.value * 0.68;

    return props.stacked ? Math.min(56, inner) : Math.min(34, inner / Math.max(1, props.series.length));
});

const bars = computed(() =>
    props.labels.flatMap((_, g) => {
        const center = pad.left + slot.value * (g + 0.5);
        let stackTop = 0;

        return props.series.map((series, s) => {
            const value = series.values[g] ?? 0;
            const x = props.stacked ? center - barWidth.value / 2 : center - (barWidth.value * props.series.length) / 2 + s * barWidth.value;
            const y0 = props.stacked ? yAt(stackTop + value) : yAt(value);
            const h = props.stacked ? yAt(stackTop) - yAt(stackTop + value) : yAt(0) - yAt(value);
            stackTop += props.stacked ? value : 0;

            return { g, s, value, x: x + 1, y: y0, w: Math.max(0, barWidth.value - 2), h: Math.max(0, h), color: colorOf(series, s) };
        });
    }),
);

// Long labels are shortened on the axis; the full text is in the tooltip and the table.
const short = (text) => (String(text).length > 14 ? `${String(text).slice(0, 13)}…` : text);

const tooltipStyle = computed(() => {
    const x = pad.left + slot.value * ((active.value ?? 0) + 0.5);
    const edge = x < 100 ? '0%' : x > width.value - 100 ? '-100%' : '-50%';

    return { left: `${x}px`, top: `${pad.top}px`, transform: `translateX(${edge})` };
});

const onKey = (event) => {
    if (!['ArrowLeft', 'ArrowRight', 'Escape'].includes(event.key)) return;
    event.preventDefault();
    if (event.key === 'Escape') active.value = null;
    else active.value = Math.min(groups.value - 1, Math.max(0, (active.value ?? (event.key === 'ArrowRight' ? -1 : groups.value)) + (event.key === 'ArrowRight' ? 1 : -1)));
};

onMounted(() => {
    width.value = box.value?.clientWidth || width.value;
    observer = new ResizeObserver(([entry]) => (width.value = Math.floor(entry.contentRect.width) || width.value));
    observer.observe(box.value);
});
onBeforeUnmount(() => observer?.disconnect());
</script>

<template>
    <div ref="box" class="relative w-full select-none">
        <ul class="mb-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-text-muted" aria-hidden="true">
            <li v-for="(s, i) in series" :key="s.name" class="inline-flex items-center gap-1.5">
                <span class="size-2.5 rounded-sm" :style="{ background: colorOf(s, i) }" />{{ s.name }}
            </li>
        </ul>

        <div
            class="relative rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
            tabindex="0"
            role="group"
            :aria-label="`${label}. Use the left and right arrow keys to read each group.`"
            @pointerleave="active = null"
            @keydown="onKey"
            @blur="active = null"
        >
            <svg :width="width" :height="height" :viewBox="`0 0 ${width} ${height}`" aria-hidden="true" class="block overflow-visible">
                <g v-for="tick in yTicks" :key="tick.value">
                    <line :x1="pad.left" :x2="width - pad.right" :y1="tick.y" :y2="tick.y" stroke="var(--color-border)" :stroke-dasharray="tick.value === 0 ? '0' : '3 4'" />
                    <text :x="pad.left - 8" :y="tick.y + 4" text-anchor="end" font-size="11" fill="var(--color-text-muted)">{{ formatCompact(tick.value) }}</text>
                </g>

                <rect
                    v-for="g in groups"
                    :key="`hover-${g}`"
                    :x="pad.left + slot * (g - 1)"
                    :y="pad.top"
                    :width="slot"
                    :height="plotH"
                    :fill="active === g - 1 ? 'var(--color-surface-muted)' : 'transparent'"
                    @pointerenter="active = g - 1"
                />

                <rect
                    v-for="bar in bars"
                    :key="`${bar.g}-${bar.s}`"
                    :x="bar.x"
                    :y="bar.y"
                    :width="bar.w"
                    :height="bar.h"
                    :rx="Math.min(5, bar.w / 2)"
                    :fill="bar.color"
                    class="chart-grow cursor-pointer"
                    :style="{ animationDelay: `${bar.g * 50}ms` }"
                    @pointerenter="active = bar.g"
                    @click="emit('select', { group: labels[bar.g], series: series[bar.s].name, index: bar.s })"
                />

                <text v-for="(l, g) in labels" :key="l" :x="pad.left + slot * (g + 0.5)" :y="height - 20" text-anchor="middle" font-size="11" fill="var(--color-text-muted)">{{ short(l) }}</text>
            </svg>

            <div
                v-if="active !== null"
                class="pointer-events-none absolute z-10 min-w-40 rounded-lg border border-border bg-surface px-3 py-2 text-xs shadow-lg"
                :style="tooltipStyle"
                aria-hidden="true"
            >
                <p class="mb-1 font-semibold text-text">{{ labels[active] }}</p>
                <p v-for="(s, i) in series" :key="s.name" class="flex items-center justify-between gap-4 text-text-muted">
                    <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-sm" :style="{ background: colorOf(s, i) }" />{{ s.name }}</span>
                    <strong class="text-text">{{ formatValue(s.values[active] ?? 0) }}</strong>
                </p>
            </div>
        </div>

        <table class="sr-only">
            <caption>{{ label }}</caption>
            <thead>
                <tr>
                    <th scope="col">Group</th>
                    <th v-for="s in series" :key="s.name" scope="col">{{ s.name }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(l, g) in labels" :key="l">
                    <th scope="row">{{ l }}</th>
                    <td v-for="s in series" :key="s.name">{{ formatValue(s.values[g] ?? 0) }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
