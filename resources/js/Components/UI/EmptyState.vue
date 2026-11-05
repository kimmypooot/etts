<script setup>
import Icon from './Icon.vue';

defineProps({
    title: {
        type: String,
        required: true,
    },
    description: {
        type: String,
        default: null,
    },
    icon: {
        type: String,
        default: null,
    },
});
</script>

<template>
    <div class="flex flex-col items-center gap-2 px-4 py-12 text-center">
        <Icon v-if="icon" :name="icon" size="lg" class="text-text-muted" />
        <p class="text-sm font-medium text-text">{{ title }}</p>
        <p v-if="description" class="max-w-sm text-sm text-text-muted">{{ description }}</p>
        <div v-if="$slots.action" class="mt-2">
            <slot name="action" />
        </div>
    </div>
</template>
