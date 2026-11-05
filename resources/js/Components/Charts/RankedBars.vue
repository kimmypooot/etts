<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { formatNumber, seriesColors, toneColors } from './palette.js';

/**
 * A ranked list: each row a label, a bar scaled to the biggest value, and the
 * number. Reads better than a chart for names that are long or many. A row with
 * an `href` is a link.
 */
const props = defineProps({
    items: { type: Array, required: true }, // [{ label, value, hint?, href? }]
    color: { type: String, default: 'info' },
    format: { type: Function, default: formatNumber },
    emptyText: { type: String, default: 'Nothing to show yet.' },
});

const fill = computed(() => toneColors[props.color] ?? props.color ?? seriesColors[0]);
const max = computed(() => Math.max(1, ...props.items.map((item) => item.value)));
</script>

<template>
    <p v-if="items.length === 0" class="py-6 text-center text-sm text-text-muted">{{ emptyText }}</p>
    <ol v-else class="space-y-3">
        <li v-for="(item, i) in items" :key="item.label">
            <component
                :is="item.href ? Link : 'div'"
                :href="item.href"
                class="group block rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
            >
                <div class="mb-1 flex items-baseline justify-between gap-3 text-sm">
                    <span class="min-w-0 truncate font-medium text-text group-hover:text-primary" :title="item.label">
                        <span class="mr-2 inline-block w-4 text-right text-xs text-text-muted tabular-nums">{{ i + 1 }}</span>{{ item.label }}
                    </span>
                    <span class="shrink-0 font-semibold text-text tabular-nums">{{ format(item.value) }}</span>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-surface-muted" role="presentation">
                    <div
                        class="chart-sweep h-full rounded-full"
                        :style="{ width: `${(item.value / max) * 100}%`, background: fill, animationDelay: `${i * 60}ms` }"
                    />
                </div>
                <p v-if="item.hint" class="mt-0.5 text-xs text-text-muted">{{ item.hint }}</p>
            </component>
        </li>
    </ol>
</template>
