<script setup>
defineProps({
    modelValue: {
        type: [String, Number],
        default: '',
    },
    id: {
        type: String,
        required: true,
    },
    options: {
        type: Array,
        required: true,
        /** [{ value, label }] or groups [{ label, options: [{ value, label }] }] */
    },
    invalid: {
        type: Boolean,
        default: false,
    },
    required: {
        type: Boolean,
        default: false,
    },
    placeholder: {
        type: String,
        default: null,
    },
    disabled: {
        type: Boolean,
        default: false,
    },
});

defineEmits(['update:modelValue']);
</script>

<template>
    <select
        :id="id"
        :value="modelValue"
        :required="required"
        :aria-invalid="invalid"
        :disabled="disabled"
        class="block w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-sm text-text shadow-xs focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring disabled:cursor-not-allowed disabled:bg-surface-muted disabled:text-text-muted"
        @change="$emit('update:modelValue', $event.target.value)"
    >
        <option v-if="placeholder" value="" disabled>{{ placeholder }}</option>
        <template v-for="option in options" :key="option.value ?? option.label">
            <!-- { label, options: [...] } renders as an optgroup. -->
            <optgroup v-if="option.options" :label="option.label">
                <option v-for="child in option.options" :key="child.value" :value="child.value">{{ child.label }}</option>
            </optgroup>
            <option v-else :value="option.value">{{ option.label }}</option>
        </template>
    </select>
</template>
