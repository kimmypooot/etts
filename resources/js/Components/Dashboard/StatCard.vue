<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import Icon from '../UI/Icon.vue';
import AnimatedNumber from '../Charts/AnimatedNumber.vue';
import Sparkline from '../Charts/Sparkline.vue';
import DeltaBadge from './DeltaBadge.vue';
import { toneColors } from '../Charts/palette.js';

/**
 * One headline figure: an icon bubble, the label, the number (counting up), how
 * it moved, a little trend line, and a short note. A card with an `href` is one
 * big link to where the figure comes from.
 */
const props = defineProps({
    label: { type: String, required: true },
    value: { type: Number, required: true },
    format: { type: String, default: 'number' }, // number | peso | decimal
    icon: { type: String, required: true },
    tone: { type: String, default: 'primary' },
    delta: { type: Number, default: null },
    showDelta: { type: Boolean, default: false },
    goodWhen: { type: String, default: 'up' },
    note: { type: String, default: null },
    spark: { type: Array, default: null },
    href: { type: String, default: null },
});

const bubble = {
    primary: 'bg-csc-blue text-white',
    info: 'bg-csc-blue-light text-white',
    success: 'bg-success text-white',
    warning: 'bg-[#b97e00] text-white',
    danger: 'bg-csc-red text-white',
};

const color = computed(() => toneColors[props.tone] ?? toneColors.primary);
</script>

<template>
    <component
        :is="href ? Link : 'section'"
        :href="href"
        class="surface-raised block h-full p-4 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
        :class="href ? 'surface-lift' : ''"
        :aria-label="href ? `${label}: open` : undefined"
    >
        <div class="flex items-start justify-between gap-3">
            <span class="flex size-10 shrink-0 items-center justify-center rounded-xl shadow-sm" :class="bubble[tone] ?? bubble.primary" aria-hidden="true">
                <Icon :name="icon" size="sm" />
            </span>
            <Sparkline v-if="spark" :values="spark" :color="color" />
        </div>

        <p class="mt-3 text-xs font-semibold tracking-wide text-text-muted uppercase">{{ label }}</p>
        <p class="mt-0.5 text-3xl font-bold tracking-tight text-text"><AnimatedNumber :value="value" :format="format" /></p>

        <div class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-text-muted">
            <DeltaBadge v-if="showDelta" :delta="delta" :good-when="goodWhen" />
            <span v-if="note">{{ note }}</span>
        </div>
    </component>
</template>
