<script setup>
import TextInput from './TextInput.vue';
import InputError from './InputError.vue';

/**
 * A text-like input with its own validation message underneath: the pair every
 * form line needs. Everything TextInput takes is passed through.
 */
defineProps({
    modelValue: { type: [String, Number], default: '' },
    id: { type: String, required: true },
    label: { type: String, required: true },
    type: { type: String, default: 'text' },
    required: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    error: { type: String, default: null },
});

defineEmits(['update:modelValue']);
</script>

<template>
    <div>
        <TextInput
            :id="id"
            :model-value="modelValue ?? ''"
            :label="label"
            :type="type"
            :required="required"
            :disabled="disabled"
            :invalid="!!error"
            @update:model-value="$emit('update:modelValue', $event)"
        />
        <InputError :message="error" />
    </div>
</template>
