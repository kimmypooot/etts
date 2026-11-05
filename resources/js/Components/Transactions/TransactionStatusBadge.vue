<script setup>
import { computed } from 'vue';
import { toneClasses } from '../UI/tones';

/**
 * A transaction status as a pill. The word is always shown, so colour is never
 * the only signal. The status strings are the ones in TransactionStatus.php.
 */
const props = defineProps({
    status: { type: String, required: true },
});

const tones = {
    submitted: 'neutral',
    forwarded: 'info',
    received: 'info',
    Completed: 'warning',
    Released: 'success',
    Approved: 'success',
    'Approved/Released': 'success',
    Disapproved: 'danger',
    Returned: 'warning',
    Cancelled: 'danger',
};

const classes = computed(() => toneClasses[tones[props.status] ?? 'neutral']);
const label = computed(() => props.status.charAt(0).toUpperCase() + props.status.slice(1));
</script>

<template>
    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap" :class="classes">
        <span class="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true" />
        {{ label }}
    </span>
</template>
