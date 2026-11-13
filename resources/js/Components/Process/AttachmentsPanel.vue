<script setup>
import { computed, ref } from 'vue';
import Icon from '@/Components/UI/Icon.vue';

// The applicant's files, beside the steps. Every view goes through the
// audited attachment route.
const props = defineProps({
    attachments: { type: Array, default: () => [] },
});

const labels = { 1: 'Valid ID', 2: 'Original document' };
const active = ref(props.attachments[0]?.slot ?? 1);
const current = computed(() => props.attachments.find((a) => a.slot === active.value));
const isPdf = computed(() => current.value?.type?.toLowerCase() === 'pdf');
</script>

<template>
    <aside class="rounded-lg border border-border bg-surface p-4 lg:sticky lg:top-4" aria-labelledby="attachments-heading">
        <div class="mb-3 flex items-center justify-between">
            <h2 id="attachments-heading" class="text-sm font-semibold text-text">Attachments</h2>
            <span class="rounded-full bg-surface-muted px-2 py-0.5 text-xs text-text-muted">{{ attachments.length }}/2</span>
        </div>

        <div class="mb-3 flex gap-1" role="tablist" aria-label="Attachment">
            <button
                v-for="slot in [1, 2]"
                :key="slot"
                type="button"
                role="tab"
                :aria-selected="active === slot"
                class="flex-1 rounded-md px-2 py-1.5 text-xs font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                :class="active === slot ? 'bg-primary/10 text-primary' : 'text-text-muted hover:bg-surface-muted'"
                @click="active = slot"
            >
                {{ labels[slot] }}
            </button>
        </div>

        <div v-if="current" class="space-y-2">
            <iframe v-if="isPdf" :src="current.url" :title="labels[active]" class="h-96 w-full rounded-md border border-border" />
            <a v-else :href="current.url" target="_blank" rel="noopener">
                <img :src="current.url" :alt="labels[active]" class="max-h-96 w-full rounded-md border border-border object-contain" />
            </a>
            <a :href="current.url" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 text-sm font-medium text-primary hover:underline">
                <Icon name="externalLink" size="sm" />
                Open in a new tab
            </a>
        </div>
        <p v-else class="rounded-md bg-surface-muted px-3 py-6 text-center text-sm text-text-muted">No {{ labels[active].toLowerCase() }} on file. Upload it in step 4.</p>
    </aside>
</template>
