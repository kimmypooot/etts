<script setup>
import { computed, useId } from 'vue';

/**
 * A labelled input sized for a touch screen: a large field with a clear label,
 * an optional hint, and the validation message tied to it for screen readers.
 * Works for text, date, tel, email, number and a textarea (`multiline`).
 */
const props = defineProps({
    modelValue: { type: [String, Number], default: '' },
    label: { type: String, required: true },
    type: { type: String, default: 'text' },
    hint: { type: String, default: null },
    error: { type: String, default: null },
    required: { type: Boolean, default: false },
    multiline: { type: Boolean, default: false },
    autocomplete: { type: String, default: 'off' },
    maxlength: { type: Number, default: null },
    inputmode: { type: String, default: null },
    max: { type: String, default: null },
});

defineEmits(['update:modelValue']);

const id = useId();
const described = computed(() => (props.error ? `${id}-error` : props.hint ? `${id}-hint` : undefined));
const field = computed(() => [
    'block w-full rounded-2xl border-2 bg-surface px-5 text-lg text-text shadow-sm transition placeholder:text-text-muted/60',
    'focus:outline-none focus:ring-4 focus:ring-csc-blue-light/25',
    props.multiline ? 'min-h-28 py-3.5' : 'h-14',
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
        <textarea
            v-if="multiline"
            :id="id"
            :value="modelValue"
            :class="field"
            :required="required"
            :maxlength="maxlength"
            :autocomplete="autocomplete"
            :aria-invalid="!!error"
            :aria-describedby="described"
            @input="$emit('update:modelValue', $event.target.value)"
        />
        <input
            v-else
            :id="id"
            :value="modelValue"
            :type="type"
            :class="field"
            :required="required"
            :maxlength="maxlength"
            :autocomplete="autocomplete"
            :inputmode="inputmode"
            :max="max"
            :aria-invalid="!!error"
            :aria-describedby="described"
            @input="$emit('update:modelValue', $event.target.value)"
        />
        <p v-if="error" :id="`${id}-error`" class="mt-1.5 flex items-center gap-1.5 text-sm font-medium text-danger" role="alert">
            <svg viewBox="0 0 20 20" class="size-4 shrink-0" fill="currentColor" aria-hidden="true"><path d="M10 2 1 18h18L10 2Zm1 12H9v-2h2v2Zm0-4H9V7h2v3Z" /></svg>
            {{ error }}
        </p>
        <p v-else-if="hint" :id="`${id}-hint`" class="mt-1.5 text-sm text-text-muted">{{ hint }}</p>
    </div>
</template>
