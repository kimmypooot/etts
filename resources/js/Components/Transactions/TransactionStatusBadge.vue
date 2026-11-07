<script setup>
import { computed } from 'vue';
import { statusFills, statusLabel } from './statusTones';

/**
 * A transaction status as a solid pill in the legacy board's colours. The word is always shown, so colour is never
 * the only signal. The status strings are the ones in TransactionStatus.php.
 */
const props = defineProps({
    status: { type: String, required: true },
});

const classes = computed(() => statusFills[props.status] ?? 'bg-st-to-be-received');
const label = computed(() => statusLabel(props.status));
</script>

<template>
    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap text-white" :class="classes">
        <span class="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true" />
        {{ label }}
    </span>
</template>
