<script setup>
import Icon from '@/Components/UI/Icon.vue';
import { formatDate } from '@/Utils/format.js';

/**
 * Document Logistics: where a SPELS application's documents are, step by step
 * (App\Services\Transactions\DocumentLogistics). Read-only.
 */
defineProps({
    steps: { type: Array, required: true },
});
</script>

<template>
    <aside class="mb-6 rounded-lg border border-border bg-surface p-5 lg:sticky lg:top-4" aria-labelledby="logistics-heading">
        <h2 id="logistics-heading" class="mb-4 text-base font-semibold text-text">Document Logistics</h2>
        <ol class="space-y-0">
            <li v-for="(step, index) in steps" :key="step.key" class="relative flex gap-3 pb-5 last:pb-0">
                <span v-if="index < steps.length - 1" class="absolute top-6 left-3 h-[calc(100%-1.5rem)] w-px" :class="step.done ? 'bg-success' : 'bg-border'" aria-hidden="true" />
                <span
                    class="relative z-10 flex size-6 shrink-0 items-center justify-center rounded-full border-2"
                    :class="step.done ? 'border-success bg-success text-white' : 'border-border-strong bg-surface text-text-muted'"
                    aria-hidden="true"
                >
                    <Icon v-if="step.done" name="check" size="sm" />
                </span>
                <div class="min-w-0 text-sm">
                    <p class="font-medium" :class="step.done ? 'text-text' : 'text-text-muted'">
                        {{ step.label }}<span class="sr-only">{{ step.done ? ' (done)' : ' (not yet)' }}</span>
                    </p>
                    <p v-if="step.date" class="text-text-muted tabular-nums">{{ formatDate(step.date) }}</p>
                    <p v-if="step.detail" class="break-words text-text-muted">{{ step.detail }}</p>
                </div>
            </li>
        </ol>
    </aside>
</template>
