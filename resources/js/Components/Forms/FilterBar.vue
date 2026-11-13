<script setup>
import Button from '../UI/Button.vue';
import Icon from '../UI/Icon.vue';

/**
 * The one filter card every list page uses: optional quick filters (status
 * chips) on top, then the labelled fields on one wrapping row, with "Clear
 * filters" at the end of that row. The clear button keeps its place when it
 * is not needed (hidden, not removed), so the card does not change height as
 * filters are set and cleared.
 */
defineProps({
    /** True when any filter differs from the page's defaults. */
    active: { type: Boolean, default: false },
});

defineEmits(['clear']);
</script>

<template>
    <section class="mb-4 surface-raised p-4" aria-label="Filters">
        <div v-if="$slots.chips" class="mb-4 border-b border-border pb-4">
            <slot name="chips" />
        </div>

        <div class="flex flex-wrap items-end gap-x-3 gap-y-4">
            <slot />

            <Button variant="ghost" size="sm" class="ml-auto" :class="{ invisible: !active }" :aria-hidden="!active" @click="$emit('clear')">
                <Icon name="close" size="sm" />
                Clear filters
            </Button>
        </div>
    </section>
</template>
