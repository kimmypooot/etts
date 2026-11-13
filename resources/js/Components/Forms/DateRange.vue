<script setup>
/** A from/to date pair for a FilterField (label it with `forId="from"`). */
defineProps({
    from: { type: String, default: '' },
    to: { type: String, default: '' },
    idFrom: { type: String, default: 'from' },
    idTo: { type: String, default: 'to' },
});

defineEmits(['update:from', 'update:to']);

const inputClass =
    'block w-full min-w-0 rounded-md border border-border-strong bg-surface px-3 py-2 text-sm text-text shadow-xs focus:border-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring';
</script>

<template>
    <div class="flex min-w-0 items-center gap-2">
        <input :id="idFrom" type="date" :value="from" aria-label="From" :class="inputClass" @input="$emit('update:from', $event.target.value)" />
        <span class="text-text-muted" aria-hidden="true">–</span>
        <input :id="idTo" type="date" :value="to" aria-label="To" :class="inputClass" @input="$emit('update:to', $event.target.value)" />
    </div>
</template>
