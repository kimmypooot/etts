<script setup>
import { computed } from 'vue';
import { titleCase } from '../../Utils/text';
import { toneClasses } from './tones';

/**
 * The legacy `status-badge`: a rounded pill with a leading dot. Pass the
 * raw status value; known values get their tone (shared with Badge, see
 * tones.js), anything else is neutral grey.
 * The word is always shown, so color is never the only signal.
 */
const props = defineProps({
    status: { type: String, required: true },
    label: { type: String, default: null },
});

const statusTones = {
    active: 'success',
    published: 'success',
    success: 'success',
    logged_out: 'neutral',
    inactive: 'danger',
    expired: 'warning',
    maintenance: 'warning',
    draft: 'neutral',
    warning: 'warning',
    critical: 'danger',
    info: 'info',
};

const classes = computed(() => toneClasses[statusTones[props.status] ?? 'neutral']);
// Statuses read as Title Case ("Returned for Correction"), whatever the source.
const text = computed(() => titleCase(props.label ?? props.status.replace(/_/g, ' ')));
</script>

<template>
    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="classes">
        <span class="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true" />
        {{ text }}
    </span>
</template>
