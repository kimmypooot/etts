<script setup>
const props = defineProps({
    modelValue: {
        type: String,
        default: '',
    },
    id: {
        type: String,
        required: true,
    },
    label: {
        type: String,
        required: true,
    },
    type: {
        type: String,
        default: 'text',
    },
    autocomplete: {
        type: String,
        default: 'off',
    },
    required: {
        type: Boolean,
        default: false,
    },
    autofocus: {
        type: Boolean,
        default: false,
    },
    invalid: {
        type: Boolean,
        default: false,
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    // Values offered as you type (a native datalist); any other value is still allowed.
    suggestions: {
        type: Array,
        default: () => [],
    },
});

defineEmits(['update:modelValue']);
</script>

<template>
    <div>
        <label :for="id" class="block text-sm font-medium text-text">{{ label }}</label>
        <input
            :id="id"
            :type="type"
            :value="modelValue"
            :autocomplete="autocomplete"
            :required="required"
            :autofocus="autofocus"
            :aria-invalid="invalid"
            :disabled="disabled"
            :list="suggestions.length ? `${id}-suggestions` : undefined"
            class="mt-1 block w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-sm text-text shadow-xs focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring disabled:cursor-not-allowed disabled:bg-surface-muted disabled:text-text-muted"
            @input="$emit('update:modelValue', $event.target.value)"
        />
        <datalist v-if="suggestions.length" :id="`${id}-suggestions`">
            <option v-for="suggestion in suggestions" :key="suggestion" :value="suggestion" />
        </datalist>
    </div>
</template>
