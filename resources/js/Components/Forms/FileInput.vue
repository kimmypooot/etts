<script setup>
defineProps({
    id: {
        type: String,
        required: true,
    },
    accept: {
        type: String,
        default: null,
    },
    required: {
        type: Boolean,
        default: false,
    },
    invalid: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['update:modelValue']);

const onChange = (event) => {
    emit('update:modelValue', event.target.files?.[0] ?? null);
};
</script>

<template>
    <input
        :id="id"
        type="file"
        :accept="accept"
        :required="required"
        :aria-invalid="invalid"
        class="block w-full text-sm text-text file:mr-3 file:rounded-md file:border-0 file:bg-surface-muted file:px-3 file:py-2 file:text-sm file:font-medium file:text-text hover:file:bg-border/40"
        @change="onChange"
    />
</template>
