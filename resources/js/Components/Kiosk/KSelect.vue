<script setup>
import { computed, useId } from 'vue';

/**
 * A labelled pick list sized for a touch screen, matching KField: a large
 * select with a clear label, an optional hint, and the validation message tied
 * to it for screen readers. Options are plain strings.
 */
const props = defineProps({
    modelValue: { type: String, default: '' },
    label: { type: String, required: true },
    options: { type: Array, required: true },
    placeholder: { type: String, default: 'Choose from the list…' },
    hint: { type: String, default: null },
    error: { type: String, default: null },
    required: { type: Boolean, default: false },
});

defineEmits(['update:modelValue']);

const id = useId();
const described = computed(() => (props.error ? `${id}-error` : props.hint ? `${id}-hint` : undefined));
const field = computed(() => [
    'block h-14 w-full rounded-2xl border-2 bg-surface px-5 text-lg text-text shadow-sm transition',
    'focus:outline-none focus:ring-4 focus:ring-csc-blue-light/25',
    props.error ? 'border-csc-red focus:border-csc-red' : 'border-border-strong/40 focus:border-csc-blue',
]);
</script>

<template>
    <div>
        <label :for="id" class="mb-1.5 flex items-baseline gap-1.5 text-base font-semibold text-text">
            {{ label }}
            <span v-if="required" class="text-csc-red" aria-hidden="true">*</span>
            <span v-else class="text-xs font-normal text-text-muted">optional</span>
        </label>
        <select
            :id="id"
            :value="modelValue ?? ''"
            :class="field"
            :required="required"
            :aria-invalid="!!error"
            :aria-describedby="described"
            @change="$emit('update:modelValue', $event.target.value)"
        >
            <option value="">{{ placeholder }}</option>
            <option v-for="option in options" :key="option" :value="option">{{ option }}</option>
        </select>
        <p v-if="error" :id="`${id}-error`" class="mt-1.5 flex items-center gap-1.5 text-sm font-medium text-danger" role="alert">
            <svg viewBox="0 0 20 20" class="size-4 shrink-0" fill="currentColor" aria-hidden="true"><path d="M10 2 1 18h18L10 2Zm1 12H9v-2h2v2Zm0-4H9V7h2v3Z" /></svg>
            {{ error }}
        </p>
        <p v-else-if="hint" :id="`${id}-hint`" class="mt-1.5 text-sm text-text-muted">{{ hint }}</p>
    </div>
</template>
