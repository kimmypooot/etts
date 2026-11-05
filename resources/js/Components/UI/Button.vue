<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import Spinner from './Spinner.vue';

/**
 * The one button component for the app. Presentation/behavior only — it
 * must never know about users, roles, services, or any other future
 * business module. Pass `href` for navigation that should look like a
 * button: it renders an Inertia <Link>, or with `external` a plain <a>
 * opening in a new tab (system launches, printable forms). A disabled one
 * falls back to a disabled <button>, since links can't be disabled.
 */
const props = defineProps({
    variant: {
        type: String,
        default: 'primary',
        validator: (value) => ['primary', 'secondary', 'outline', 'danger', 'success', 'ghost', 'link'].includes(value),
    },
    size: {
        type: String,
        default: 'md',
        validator: (value) => ['sm', 'md'].includes(value),
    },
    type: {
        type: String,
        default: 'button',
    },
    href: {
        type: String,
        default: null,
    },
    /** With `href`: a full-page link in a new tab instead of an Inertia visit. */
    external: {
        type: Boolean,
        default: false,
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    loading: {
        type: Boolean,
        default: false,
    },
    /** Icon-only button: pass an accessible label since there is no visible text. */
    iconOnly: {
        type: Boolean,
        default: false,
    },
});

const isDisabled = computed(() => props.disabled || props.loading);
const asLink = computed(() => props.href && !isDisabled.value);

const variantClasses = {
    primary: 'bg-primary text-white hover:bg-primary-hover',
    secondary: 'border border-border bg-surface text-text hover:bg-surface-muted',
    // Toolbar actions (Refresh, Export): quieter than primary, still branded.
    outline: 'border border-primary/40 bg-surface text-primary hover:bg-primary hover:text-white',
    danger: 'bg-danger text-white hover:bg-danger-hover',
    // Legacy "Add …" / "Save" actions were green.
    success: 'bg-success text-white hover:bg-success-hover',
    ghost: 'text-text hover:bg-surface-muted',
    link: 'text-primary hover:text-primary-hover underline-offset-2 hover:underline',
};

const sizeClasses = computed(() => {
    if (props.variant === 'link') return props.size === 'sm' ? 'gap-1.5 text-xs' : 'gap-2 text-sm';
    if (props.iconOnly) return props.size === 'sm' ? 'p-1.5 text-xs' : 'p-2 text-sm';

    return props.size === 'sm' ? 'gap-1.5 px-3 py-1.5 text-xs' : 'gap-2 px-4 py-2 text-sm';
});

const classes = computed(() => [
    'inline-flex items-center justify-center rounded-md font-medium transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50',
    variantClasses[props.variant],
    sizeClasses.value,
]);
</script>

<template>
    <a v-if="asLink && external" :href="href" target="_blank" rel="noopener noreferrer" :class="classes">
        <slot />
    </a>
    <Link v-else-if="asLink" :href="href" :class="classes">
        <slot />
    </Link>
    <button v-else :type="type" :disabled="isDisabled" :aria-busy="loading" :class="classes">
        <Spinner v-if="loading" size="sm" label="" />
        <slot />
    </button>
</template>
