<script setup>
/**
 * One labelled control in a FilterBar. The label is visible (small, muted) so
 * every filter says what it filters. `grow` takes the spare room (the search
 * box); `wide` fits a from/to date pair.
 */
defineProps({
    label: { type: String, required: true },
    forId: { type: String, default: null },
    grow: { type: Boolean, default: false },
    wide: { type: Boolean, default: false },
});
</script>

<template>
    <div class="min-w-0 w-full" :class="grow ? 'sm:min-w-64 sm:flex-[2_1_16rem]' : wide ? 'sm:w-[21rem]' : 'sm:w-44'">
        <label :for="forId" class="mb-1 block text-xs font-medium text-text-muted">{{ label }}</label>
        <slot />
    </div>
</template>
