<script setup>
import { ref, watch } from 'vue';
import Modal from '../Feedback/Modal.vue';
import Alert from '../Feedback/Alert.vue';
import Badge from '../UI/Badge.vue';
import EmptyState from '../UI/EmptyState.vue';
import TransactionStatusBadge from './TransactionStatusBadge.vue';

/**
 * One record's history, newest first: the imported legacy trail and the audit
 * entries this app writes. A deleted record shows who deleted it, when and why.
 * The `tools` slot is for actions that belong with the record (revert, delete).
 */
const props = defineProps({
    record: { type: Object, default: null },
    base: { type: String, default: '/admin/transactions' },
});

defineEmits(['close']);

const state = ref('idle'); // idle | loading | error
const timeline = ref({ entries: [], deleted: null });

watch(
    () => props.record?.id,
    async (id) => {
        timeline.value = { entries: [], deleted: null };

        if (!id) return;

        state.value = 'loading';

        try {
            const response = await fetch(`${props.base}/${id}/timeline`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });

            if (!response.ok) throw new Error(String(response.status));

            // Ignore an answer for a record the person has since closed or swapped.
            if (props.record?.id === id) {
                timeline.value = await response.json();
                state.value = 'idle';
            }
        } catch {
            if (props.record?.id === id) state.value = 'error';
        }
    },
    { immediate: true },
);

const formatWhen = (iso) =>
    iso ? new Date(iso).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' }) : '';
</script>

<template>
    <Modal :open="record !== null" :title="record ? `Transaction #${record.id}` : 'Transaction'" tone="primary" icon="clock" size="lg" @close="$emit('close')">
        <div v-if="record" class="mb-4 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
            <span class="font-medium text-text">{{ record.name }}</span>
            <span class="text-text-muted">{{ record.type }}</span>
            <TransactionStatusBadge v-if="record.status" :status="record.status" />
        </div>

        <slot name="tools" />

        <Alert v-if="timeline.deleted" tone="danger" class="mb-4">
            Deleted on {{ formatWhen(timeline.deleted.at) }} by <strong>{{ timeline.deleted.by }}</strong>.
            Reason: {{ timeline.deleted.reason }}<template v-if="timeline.deleted.note"> — {{ timeline.deleted.note }}</template>
        </Alert>

        <p v-if="state === 'loading'" class="py-8 text-center text-sm text-text-muted" role="status">Loading history…</p>
        <Alert v-else-if="state === 'error'" tone="danger">The history could not be loaded. Close this and try again.</Alert>
        <EmptyState v-else-if="timeline.entries.length === 0" icon="clock" title="No history" description="Nothing has been recorded for this transaction." />

        <ol v-else class="space-y-3" aria-label="History">
            <li v-for="(entry, index) in timeline.entries" :key="index" class="rounded-md border border-border bg-surface p-3 text-sm">
                <div class="flex flex-wrap items-center gap-2">
                    <Badge :tone="entry.source === 'legacy' ? 'neutral' : 'info'">{{ entry.action }}</Badge>
                    <span class="text-text-muted">{{ formatWhen(entry.at) }} · {{ entry.who ?? 'unknown' }}</span>
                </div>
                <p v-if="entry.details" class="mt-1 break-words whitespace-pre-line text-text">{{ entry.details }}</p>
            </li>
        </ol>
    </Modal>
</template>
