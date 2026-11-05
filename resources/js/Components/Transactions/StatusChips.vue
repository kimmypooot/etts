<script setup>
import { computed } from 'vue';
import { toneClasses } from '../UI/tones';
import { statusLabel, statusTones } from './statusTones';

/**
 * One-click status filter for a board: every status with how many records it
 * holds under the other filters. Choosing the selected one again clears it.
 * The word is always shown and the buttons are real toggles
 * (aria-pressed), so colour is never the only signal.
 */
const props = defineProps({
    /** { status: count }, in board order. */
    counts: { type: Object, required: true },
    modelValue: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue']);

const total = computed(() => Object.values(props.counts).reduce((sum, n) => sum + n, 0));
// A status with nothing in it is hidden, unless it is the one selected.
const chips = computed(() => Object.entries(props.counts).filter(([status, n]) => n > 0 || status === props.modelValue));

const toggle = (status) => emit('update:modelValue', props.modelValue === status ? '' : status);

const base = 'inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-semibold transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring';
</script>

<template>
    <div class="mb-3 flex flex-wrap items-center gap-2" role="group" aria-label="Filter by status">
        <button
            type="button"
            :class="[base, modelValue === '' ? 'border-primary bg-primary text-white' : 'border-border bg-surface text-text hover:bg-surface-muted']"
            :aria-pressed="modelValue === ''"
            @click="emit('update:modelValue', '')"
        >
            All
            <span class="tabular-nums opacity-80">{{ total.toLocaleString('en-PH') }}</span>
        </button>

        <button
            v-for="[status, n] in chips"
            :key="status"
            type="button"
            :class="[base, modelValue === status ? toneClasses[statusTones[status] ?? 'neutral'] + ' ring-2' : 'border-border bg-surface text-text hover:bg-surface-muted']"
            :aria-pressed="modelValue === status"
            @click="toggle(status)"
        >
            <span class="size-1.5 rounded-full" :class="`${toneClasses[statusTones[status] ?? 'neutral'].split(' ')[0]} ring-1 ring-current`" aria-hidden="true" />
            {{ statusLabel(status) }}
            <span class="tabular-nums opacity-80">{{ n.toLocaleString('en-PH') }}</span>
        </button>
    </div>
</template>
