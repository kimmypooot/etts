<script setup>
import Icon from '@/Components/UI/Icon.vue';

// The five steps. A step is reachable once every step before it is complete
// (`reachable` is the furthest one); done steps show a check.
defineProps({
    steps: { type: Array, required: true },
    current: { type: Number, required: true },
    reachable: { type: Number, required: true },
});

defineEmits(['go']);
</script>

<template>
    <nav aria-label="Processing steps" class="mb-6 overflow-x-auto">
        <ol class="flex min-w-max gap-2">
            <li v-for="step in steps" :key="step.number" class="flex items-center gap-2">
                <button
                    type="button"
                    :disabled="step.number > reachable"
                    :aria-current="step.number === current ? 'step' : undefined"
                    class="flex items-center gap-2 rounded-full border px-3 py-1.5 text-sm font-medium transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring disabled:cursor-not-allowed disabled:opacity-50"
                    :class="
                        step.number === current
                            ? 'border-primary bg-primary text-white'
                            : 'border-border bg-surface text-text hover:border-primary hover:text-primary'
                    "
                    @click="$emit('go', step.number)"
                >
                    <span
                        class="flex size-5 items-center justify-center rounded-full text-xs tabular-nums"
                        :class="step.number === current ? 'bg-white/20' : step.number < 5 && step.missing.length === 0 ? 'bg-success-bg text-success' : 'bg-surface-muted'"
                    >
                        <Icon v-if="step.number !== current && step.number < 5 && step.missing.length === 0" name="check" size="sm" />
                        <template v-else>{{ step.number }}</template>
                    </span>
                    {{ step.label }}
                </button>
                <Icon v-if="step.number < steps.length" name="chevronRight" size="sm" class="text-text-muted" />
            </li>
        </ol>
    </nav>
</template>
