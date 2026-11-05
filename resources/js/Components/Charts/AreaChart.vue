<script setup>
import { computed, onBeforeUnmount, onMounted, ref, useId } from 'vue';
import { formatCompact, formatNumber, niceMax, seriesColors } from './palette.js';

/**
 * A smooth area chart over a shared set of labels, with gridlines, a hover (or
 * arrow-key) read-out of every series at one point, and a text table for
 * screen readers. Drawn in plain SVG and sized to its container, so text never
 * stretches. Colours come from the chart palette unless a series sets its own.
 */
const props = defineProps({
    labels: { type: Array, required: true },
    series: { type: Array, required: true }, // [{ name, values, color? }]
    height: { type: Number, default: 260 },
    formatValue: { type: Function, default: formatNumber },
    label: { type: String, default: 'Trend' },
});

const id = useId();
const box = ref(null);
const width = ref(640);
const active = ref(null);
let observer;

const pad = { top: 14, right: 14, bottom: 30, left: 42 };
const plotW = computed(() => Math.max(10, width.value - pad.left - pad.right));
const plotH = computed(() => props.height - pad.top - pad.bottom);
const count = computed(() => props.labels.length);
const yMax = computed(() => niceMax(Math.max(0, ...props.series.flatMap((s) => s.values))));

const xAt = (i) => pad.left + (count.value > 1 ? (i * plotW.value) / (count.value - 1) : plotW.value / 2);
const yAt = (value) => pad.top + plotH.value - (value / yMax.value) * plotH.value;
const colorOf = (series, i) => series.color ?? seriesColors[i % seriesColors.length];

const yTicks = computed(() => [0, 1, 2, 3, 4].map((t) => ({ value: (yMax.value * t) / 4, y: yAt((yMax.value * t) / 4) })));

// Labels thin out so they never collide: about one per 64px.
const every = computed(() => Math.max(1, Math.ceil(count.value / Math.max(1, Math.floor(plotW.value / 64)))));
const xTicks = computed(() => props.labels.map((label, i) => ({ label, x: xAt(i), i })).filter((tick) => tick.i % every.value === 0 || tick.i === count.value - 1 && every.value === 1));

const clamp = (value, low, high) => Math.min(high, Math.max(low, value));

/** A smooth line through the points whose curve never dips below or above its neighbours. */
const smooth = (points) => {
    if (points.length === 0) return '';
    let d = `M${points[0][0].toFixed(1)},${points[0][1].toFixed(1)}`;

    for (let i = 0; i < points.length - 1; i++) {
        const p0 = points[i - 1] ?? points[i];
        const p1 = points[i];
        const p2 = points[i + 1];
        const p3 = points[i + 2] ?? p2;
        const low = Math.min(p1[1], p2[1]);
        const high = Math.max(p1[1], p2[1]);
        const c1y = clamp(p1[1] + (p2[1] - p0[1]) / 6, low, high);
        const c2y = clamp(p2[1] - (p3[1] - p1[1]) / 6, low, high);
        d += ` C${(p1[0] + (p2[0] - p0[0]) / 6).toFixed(1)},${c1y.toFixed(1)} ${(p2[0] - (p3[0] - p1[0]) / 6).toFixed(1)},${c2y.toFixed(1)} ${p2[0].toFixed(1)},${p2[1].toFixed(1)}`;
    }

    return d;
};

const paths = computed(() =>
    props.series.map((series, index) => {
        const points = series.values.map((value, i) => [xAt(i), yAt(value)]);
        const line = smooth(points);
        const bottom = pad.top + plotH.value;

        return {
            name: series.name,
            color: colorOf(series, index),
            line,
            area: points.length ? `${line} L${points[points.length - 1][0].toFixed(1)},${bottom} L${points[0][0].toFixed(1)},${bottom} Z` : '',
            points,
        };
    }),
);

const locate = (event) => {
    const rect = event.currentTarget.getBoundingClientRect();
    const step = count.value > 1 ? plotW.value / (count.value - 1) : 1;
    active.value = clamp(Math.round((event.clientX - rect.left - pad.left) / step), 0, count.value - 1);
};

const onKey = (event) => {
    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End', 'Escape'].includes(event.key)) return;
    event.preventDefault();
    if (event.key === 'Escape') active.value = null;
    else if (event.key === 'Home') active.value = 0;
    else if (event.key === 'End') active.value = count.value - 1;
    else active.value = clamp((active.value ?? (event.key === 'ArrowRight' ? -1 : count.value)) + (event.key === 'ArrowRight' ? 1 : -1), 0, count.value - 1);
};

const tooltipStyle = computed(() => {
    const x = xAt(active.value ?? 0);
    const edge = x < 96 ? '0%' : x > width.value - 96 ? '-100%' : '-50%';

    return { left: `${x}px`, top: `${pad.top}px`, transform: `translateX(${edge})` };
});

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
            <li v-for="path in paths" :key="path.name" class="inline-flex items-center gap-1.5">
                <span class="size-2.5 rounded-full" :style="{ background: path.color }" />{{ path.name }}
            </li>
        </ul>

        <div
            class="relative rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
            tabindex="0"
            role="group"
            :aria-label="`${label}. Use the left and right arrow keys to read each point.`"
            @pointermove="locate"
            @pointerleave="active = null"
            @keydown="onKey"
            @blur="active = null"
        >
            <svg :width="width" :height="height" :viewBox="`0 0 ${width} ${height}`" aria-hidden="true" class="block overflow-visible">
                <defs>
                    <linearGradient v-for="(path, i) in paths" :id="`area-${id}-${i}`" :key="i" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" :stop-color="path.color" stop-opacity="0.32" />
                        <stop offset="100%" :stop-color="path.color" stop-opacity="0.02" />
                    </linearGradient>
                </defs>

                <g v-for="tick in yTicks" :key="tick.value">
                    <line :x1="pad.left" :x2="width - pad.right" :y1="tick.y" :y2="tick.y" stroke="var(--color-border)" :stroke-dasharray="tick.value === 0 ? '0' : '3 4'" />
                    <text :x="pad.left - 8" :y="tick.y + 4" text-anchor="end" font-size="11" fill="var(--color-text-muted)">{{ formatCompact(tick.value) }}</text>
                </g>

                <text v-for="tick in xTicks" :key="tick.i" :x="tick.x" :y="height - 8" text-anchor="middle" font-size="11" fill="var(--color-text-muted)">{{ tick.label }}</text>

                <g v-for="(path, i) in paths" :key="path.name">
                    <path :d="path.area" :fill="`url(#area-${id}-${i})`" class="chart-rise" />
                    <path :d="path.line" fill="none" :stroke="path.color" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="chart-sweep" />
                </g>

                <g v-if="active !== null">
                    <line :x1="xAt(active)" :x2="xAt(active)" :y1="pad.top" :y2="pad.top + plotH" stroke="var(--color-text-muted)" stroke-opacity="0.4" stroke-dasharray="4 4" />
                    <circle v-for="path in paths" :key="path.name" :cx="path.points[active][0]" :cy="path.points[active][1]" r="5" :fill="path.color" stroke="white" stroke-width="2" />
                </g>
            </svg>

            <div
                v-if="active !== null"
                class="pointer-events-none absolute z-10 min-w-36 rounded-lg border border-border bg-surface px-3 py-2 text-xs shadow-lg"
                :style="tooltipStyle"
                aria-hidden="true"
            >
                <p class="mb-1 font-semibold text-text">{{ labels[active] }}</p>
                <p v-for="path in paths" :key="path.name" class="flex items-center justify-between gap-4 text-text-muted">
                    <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full" :style="{ background: path.color }" />{{ path.name }}</span>
                    <strong class="text-text">{{ formatValue(series.find((s) => s.name === path.name).values[active]) }}</strong>
                </p>
            </div>
        </div>

        <table class="sr-only">
            <caption>{{ label }}</caption>
            <thead>
                <tr>
                    <th scope="col">Period</th>
                    <th v-for="s in series" :key="s.name" scope="col">{{ s.name }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(l, i) in labels" :key="l">
                    <th scope="row">{{ l }}</th>
                    <td v-for="s in series" :key="s.name">{{ formatValue(s.values[i]) }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
