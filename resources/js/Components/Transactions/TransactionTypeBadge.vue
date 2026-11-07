<script setup>
import { computed } from 'vue';

/**
 * A transaction type as a solid pill (white text, so the word is always shown
 * and colour is never the only signal). SPELS takes the legacy purple; the
 * issuance types take blues and teal. Colours are `--color-type-*` in app.css.
 */
const props = defineProps({
    type: { type: String, required: true },
});

const kinds = {
    'Special Eligibility Application': { label: 'SPELS', fill: 'bg-type-spels' },
    Certification: { label: 'Certification', fill: 'bg-type-cert' },
    Authentication: { label: 'Authentication', fill: 'bg-type-auth' },
    'Certification and Authentication': { label: 'Cert. + Auth.', fill: 'bg-type-cert-auth' },
};

const kind = computed(() => kinds[props.type] ?? { label: props.type, fill: 'bg-st-to-be-received' });
</script>

<template>
    <span class="inline-block rounded-md px-2 py-0.5 text-xs font-semibold whitespace-nowrap text-white" :class="kind.fill" :title="type">{{ kind.label }}</span>
</template>
