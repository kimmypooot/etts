<script setup>
/**
 * A short row of exclusive choices (a range, a view). Each is a button with
 * `aria-pressed`, so it works with the keyboard and a screen reader without
 * extra scripting; the current one is filled.
 */
defineProps({
    modelValue: { type: String, required: true },
    options: { type: Array, required: true }, // [{ value, label }]
    label: { type: String, required: true },
});

defineEmits(['update:modelValue']);
</script>

<template>
    <div class="inline-flex rounded-lg bg-surface-muted p-0.5" role="group" :aria-label="label">
        <button
            v-for="option in options"
            :key="option.value"
            type="button"
            class="rounded-md px-3 py-1 text-xs font-semibold transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
            :class="modelValue === option.value ? 'bg-surface text-primary shadow-sm' : 'text-text-muted hover:text-text'"
            :aria-pressed="modelValue === option.value"
            @click="$emit('update:modelValue', option.value)"
        >
            {{ option.label }}
        </button>
    </div>
</template>
