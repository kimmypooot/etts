<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

/**
 * The kiosk's big button: a generous touch target (at least 56px tall), clear
 * focus ring, a press effect, and a busy state. Pass `href` to make it a link.
 */
const props = defineProps({
    variant: { type: String, default: 'primary' }, // primary | secondary | ghost | danger
    href: { type: String, default: null },
    type: { type: String, default: 'button' },
    loading: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    block: { type: Boolean, default: false },
});

const styles = {
    primary: 'bg-csc-blue text-white shadow-[0_8px_20px_-8px_rgb(42_51_143/0.7)] hover:bg-csc-blue-dark',
    secondary: 'border-2 border-csc-blue/25 bg-surface text-primary hover:border-csc-blue hover:bg-info-bg',
    ghost: 'text-text-muted hover:bg-surface-muted hover:text-text',
    danger: 'bg-danger text-white hover:bg-danger-hover',
};

const classes = computed(() => [
    'inline-flex min-h-14 items-center justify-center gap-2.5 rounded-2xl px-7 text-lg font-semibold transition duration-150 select-none',
    'active:scale-[0.97] focus:outline-none focus-visible:ring-4 focus-visible:ring-csc-blue-light/40',
    'disabled:cursor-not-allowed disabled:opacity-50 disabled:active:scale-100',
    styles[props.variant] ?? styles.primary,
    props.block ? 'w-full' : '',
]);
</script>

<template>
    <Link v-if="href && !disabled" :href="href" :class="classes"><slot /></Link>
    <button v-else :type="type" :class="classes" :disabled="disabled || loading" :aria-busy="loading">
        <span v-if="loading" class="size-5 animate-spin rounded-full border-2 border-current border-t-transparent" aria-hidden="true" />
        <slot />
    </button>
</template>
