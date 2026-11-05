<script setup>
import { computed } from 'vue';

/**
 * How a figure changed against the period before: an arrow and a percentage,
 * green when it moved the good way and red the other way. The direction is also
 * spelled out in text, so colour is never the only signal. `null` (nothing to
 * compare with) shows "New".
 */
const props = defineProps({
    delta: { type: Number, default: null },
    goodWhen: { type: String, default: 'up' }, // up | down
});

const tone = computed(() => {
    if (props.delta === null || props.delta === 0) return 'bg-surface-muted text-text-muted';
    const good = (props.delta > 0) === (props.goodWhen === 'up');

    return good ? 'bg-success-bg text-success' : 'bg-danger-bg text-danger';
});

const text = computed(() => {
    if (props.delta === null) return 'New';
    if (props.delta === 0) return 'No change';

    return `${props.delta > 0 ? 'Up' : 'Down'} ${Math.abs(props.delta).toLocaleString('en-PH', { maximumFractionDigits: 1 })}%`;
});
</script>

<template>
    <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap" :class="tone">
        <svg v-if="delta" viewBox="0 0 12 12" class="size-3" aria-hidden="true">
            <path :d="delta > 0 ? 'M6 2 L10 8 H2 Z' : 'M6 10 L2 4 H10 Z'" fill="currentColor" />
        </svg>
        {{ text }}
    </span>
</template>
