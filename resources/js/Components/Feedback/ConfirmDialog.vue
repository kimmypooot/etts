<script setup>
import Modal from './Modal.vue';
import Button from '../UI/Button.vue';

/**
 * Reusable confirmation for destructive/irreversible actions — the
 * replacement for the browser's native confirm(). `processing` disables
 * both buttons and shows a spinner so a slow request can't be double-
 * submitted by an impatient click.
 */
defineProps({
    open: {
        type: Boolean,
        required: true,
    },
    title: {
        type: String,
        required: true,
    },
    danger: {
        type: Boolean,
        default: true,
    },
    confirmLabel: {
        type: String,
        default: 'Confirm',
    },
    cancelLabel: {
        type: String,
        default: 'Cancel',
    },
    processing: {
        type: Boolean,
        default: false,
    },
});

defineEmits(['confirm', 'cancel']);
</script>

<template>
    <Modal :open="open" :title="title" size="sm" @close="$emit('cancel')">
        <p class="text-sm text-text-muted">
            <slot />
        </p>

        <template #footer>
            <Button variant="secondary" :disabled="processing" @click="$emit('cancel')">
                {{ cancelLabel }}
            </Button>
            <Button
                :variant="danger ? 'danger' : 'primary'"
                :loading="processing"
                data-autofocus
                @click="$emit('confirm')"
            >
                {{ confirmLabel }}
            </Button>
        </template>
    </Modal>
</template>
