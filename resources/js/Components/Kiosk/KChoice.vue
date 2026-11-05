<script setup>
import { useId } from 'vue';

/**
 * A single choice from a few options, as big tappable pills: easier on a touch
 * screen than a dropdown and everything is visible at once. A real radio group
 * underneath, so the keyboard and screen readers work.
 */
const props = defineProps({
    modelValue: { type: String, default: '' },
    label: { type: String, required: true },
    options: { type: Array, required: true }, // [{ value, label }] or strings
    error: { type: String, default: null },
    required: { type: Boolean, default: false },
    columns: { type: String, default: 'sm:grid-cols-2' },
});

defineEmits(['update:modelValue']);

const id = useId();
const normal = props.options.map((option) => (typeof option === 'string' ? { value: option, label: option } : option));
</script>

<template>
    <fieldset>
        <legend class="mb-1.5 flex items-baseline gap-1.5 text-base font-semibold text-text">
            {{ label }}
            <span v-if="required" class="text-csc-red" aria-hidden="true">*</span>
            <span v-else class="text-xs font-normal text-text-muted">optional</span>
        </legend>
        <div class="grid gap-2.5" :class="columns">
            <label
                v-for="option in normal"
                :key="option.value"
                class="relative flex min-h-14 cursor-pointer items-center justify-center rounded-2xl border-2 px-4 py-2 text-center text-base font-medium transition duration-150 select-none active:scale-[0.98] has-[:focus-visible]:ring-4 has-[:focus-visible]:ring-csc-blue-light/40"
                :class="modelValue === option.value ? 'border-csc-blue bg-info-bg text-primary shadow-sm' : 'border-border-strong/40 bg-surface text-text hover:border-csc-blue-light'"
            >
                <input
                    type="radio"
                    class="sr-only"
                    :name="id"
                    :value="option.value"
                    :checked="modelValue === option.value"
                    @change="$emit('update:modelValue', option.value)"
                />
                <svg v-if="modelValue === option.value" viewBox="0 0 20 20" class="mr-2 size-5 shrink-0 text-csc-blue" fill="currentColor" aria-hidden="true"><path d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.7-9.3-4.5 4.5a1 1 0 0 1-1.4 0L6 11.4l1.4-1.4 1.1 1.1 3.8-3.8 1.4 1.4Z" /></svg>
                {{ option.label }}
            </label>
        </div>
        <p v-if="error" class="mt-1.5 flex items-center gap-1.5 text-sm font-medium text-danger" role="alert">{{ error }}</p>
    </fieldset>
</template>
