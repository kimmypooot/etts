<script setup>
import KioskIcon from './KioskIcon.vue';

/**
 * Where the visitor is in the form: numbered steps joined by a bar that fills
 * as they go. Completed steps show a tick and can be tapped to go back; the
 * steps ahead cannot be skipped to.
 */
defineProps({
    steps: { type: Array, required: true }, // [{ key, label }]
    current: { type: Number, required: true }, // index
});

defineEmits(['go']);
</script>

<template>
    <ol class="flex items-start" :aria-label="`Step ${current + 1} of ${steps.length}`">
        <li v-for="(step, index) in steps" :key="step.key" class="relative flex flex-1 flex-col items-center text-center" :aria-current="index === current ? 'step' : undefined">
            <span v-if="index > 0" class="absolute top-5 right-1/2 left-[-50%] h-1 rounded-full bg-border" aria-hidden="true">
                <span class="block h-full rounded-full bg-csc-blue transition-[width] duration-500 ease-out" :style="{ width: index <= current ? '100%' : '0%' }" />
            </span>
            <button
                type="button"
                class="relative z-10 flex size-10 items-center justify-center rounded-full border-2 text-sm font-semibold transition duration-300 focus:outline-none focus-visible:ring-4 focus-visible:ring-csc-blue-light/40"
                :class="index < current ? 'border-csc-blue bg-csc-blue text-white' : index === current ? 'scale-110 border-csc-blue bg-surface text-primary shadow-md' : 'border-border-strong/40 bg-surface text-text-muted'"
                :disabled="index >= current"
                :aria-label="index < current ? `Go back to ${step.label}` : step.label"
                @click="$emit('go', index)"
            >
                <KioskIcon v-if="index < current" name="check" :size="20" :stroke="2.6" />
                <template v-else>{{ index + 1 }}</template>
            </button>
            <span class="mt-2 text-xs font-medium sm:text-sm" :class="index === current ? 'text-primary' : 'text-text-muted'">{{ step.label }}</span>
        </li>
    </ol>
</template>
