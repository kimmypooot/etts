<script setup>
/**
 * Styled wrapper around a plain <table>. Consumers write normal
 * <thead>/<tbody>/<tr>/<th>/<td> markup into the default slot — this only
 * establishes the shared container, scroll behavior, and row/header
 * styling via `:deep()`, so it stays a foundation rather than a data-grid.
 * Pass `:loading="true"` to show a spinner, or leave `rows` at 0 to fall
 * back to the `empty` slot.
 */
import Spinner from './Spinner.vue';

defineProps({
    loading: {
        type: Boolean,
        default: false,
    },
    empty: {
        type: Boolean,
        default: false,
    },
});
</script>

<template>
    <div class="surface-raised overflow-hidden">
        <div v-if="loading" class="flex items-center justify-center gap-2 px-4 py-12 text-sm text-text-muted">
            <Spinner size="sm" label="" />
            Loading…
        </div>
        <div v-else-if="empty">
            <slot name="empty" />
        </div>
        <div v-else class="overflow-x-auto">
            <table class="w-full text-left text-sm table-styled">
                <slot />
            </table>
        </div>
    </div>
</template>

<style scoped>
@reference '../../../css/app.css';

/* Same header as every list table (the `table-head` utility in app.css). */
.table-styled :deep(thead) {
    @apply table-head;
    border-bottom: 1px solid var(--color-border);
}

.table-styled :deep(th) {
    padding: 0.75rem 1rem;
}

/* A sort button is a <button>, which does not inherit the header's casing: make it read like the other headers. */
.table-styled :deep(th button) {
    text-transform: inherit;
    letter-spacing: inherit;
    font-size: inherit;
    font-weight: inherit;
}

/* The sort direction, from the header's own aria-sort (a dim double arrow when it can be sorted). */
.table-styled :deep(th[aria-sort] button)::after {
    content: '↕';
    margin-left: 0.35rem;
    opacity: 0.4;
}

.table-styled :deep(th[aria-sort='ascending'] button)::after {
    content: '↑';
    opacity: 1;
}

.table-styled :deep(th[aria-sort='descending'] button)::after {
    content: '↓';
    opacity: 1;
}

.table-styled :deep(td) {
    padding: 0.75rem 1rem;
    color: var(--color-text);
}

.table-styled :deep(tbody tr) {
    border-bottom: 1px solid var(--color-border);
}

.table-styled :deep(tbody tr:last-child) {
    border-bottom: none;
}
</style>
