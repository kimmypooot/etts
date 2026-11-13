<script setup>
import Icon from '../UI/Icon.vue';

defineProps({
    modelValue: { type: String, default: '' },
    id: { type: String, default: 'search' },
    placeholder: { type: String, default: '' },
});

defineEmits(['update:modelValue']);
</script>

<template>
    <div class="relative">
        <Icon name="search" size="sm" class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-text-muted" />
        <input
            :id="id"
            type="search"
            :value="modelValue"
            :placeholder="placeholder"
            class="block w-full rounded-md border border-border-strong bg-surface py-2 pr-3 pl-9 text-sm text-text shadow-xs focus:border-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
            @input="$emit('update:modelValue', $event.target.value)"
        />
    </div>
</template>
