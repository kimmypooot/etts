<script setup>
import { computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import StaffLayout from '@/Layouts/StaffLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import Table from '@/Components/UI/Table.vue';
import Alert from '@/Components/Feedback/Alert.vue';
import { confirm } from '@/Composables/useDialog';
import { ref } from 'vue';

const props = defineProps({
    connected: { type: Boolean, required: true },
    error: { type: String, default: null },
    counts: { type: Array, required: true },
    last: { type: Object, default: null },
    result: { type: Object, default: null },
});

const running = ref(false);

// The run that just finished, else the last one on record.
const shown = computed(() => props.result ?? props.last);

const tableLabels = {
    transactions: 'Transactions',
    issuance_requests: 'Issuance requests',
    deleted_transactions: 'Deleted records',
    deleted_issuance_requests: 'Deleted issuance requests',
    transmittals: 'Transmittals',
    transmittal_items: 'Transmittal items',
    legacy_activity_logs: 'Activity history',
};

const rows = computed(() => Object.entries(shown.value?.tables ?? {}).map(([key, row]) => ({ key, label: tableLabels[key] ?? key, ...row })));

const formatWhen = (iso) => (iso ? new Date(iso).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' }) : '');

const sync = async () => {
    const confirmed = await confirm({
        title: 'Sync from the legacy database?',
        text: 'New and changed legacy records are brought in. Anything edited in this system since the last sync is kept, and nothing is removed. It can take a minute; keep this page open.',
        confirmText: 'Sync now',
        tone: 'primary',
    });

    if (!confirmed) return;

    router.post('/admin/legacy-sync', {}, {
        preserveScroll: true,
        onStart: () => (running.value = true),
        onFinish: () => (running.value = false),
    });
};
</script>

<template>
    <Head title="Sync from legacy" />

    <StaffLayout>
        <PageHeader
            title="Sync from legacy"
            description="Bring the legacy database's latest records into this system while the old app is still in use."
            :breadcrumbs="[{ label: 'Dashboard', href: '/dashboard' }, { label: 'Sync from legacy' }]"
        >
            <template #actions>
                <Button :loading="running" :disabled="!connected" @click="sync">
                    <Icon name="refresh" size="sm" />
                    {{ running ? 'Syncing…' : 'Sync now' }}
                </Button>
            </template>
        </PageHeader>

        <Alert v-if="error" tone="danger" title="Legacy database not reachable" class="mb-4">{{ error }}</Alert>

        <Alert tone="info" title="How it works" class="mb-4">
            It only reads the legacy database. A record is added when it is new, and updated when the legacy app changed it after this system did.
            A record edited here is never overwritten, and a record deleted in the legacy app is reported, not removed. Switch this tool off
            (<code>ETTS_LEGACY_SYNC=false</code>) once ETTS is the live system.
        </Alert>

        <h2 class="mb-2 text-sm font-semibold text-text">Where the two databases stand</h2>
        <Table class="mb-8">
            <thead>
                <tr>
                    <th scope="col">Records</th>
                    <th scope="col" class="text-right">Legacy</th>
                    <th scope="col" class="text-right">This system</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in counts" :key="row.label">
                    <td>{{ row.label }}</td>
                    <td class="text-right tabular-nums">{{ row.legacy === null ? '—' : row.legacy.toLocaleString('en-PH') }}</td>
                    <td class="text-right tabular-nums">{{ row.here.toLocaleString('en-PH') }}</td>
                </tr>
            </tbody>
        </Table>

        <template v-if="shown">
            <h2 class="mb-1 text-sm font-semibold text-text">{{ result ? 'This sync' : 'Last sync' }}</h2>
            <p class="mb-2 text-sm text-text-muted">{{ formatWhen(shown.at) }}<template v-if="shown.by"> · by {{ shown.by }}</template></p>

            <Table class="mb-4">
                <thead>
                    <tr>
                        <th scope="col">Table</th>
                        <th scope="col" class="text-right">Read</th>
                        <th scope="col" class="text-right">Written</th>
                        <th scope="col" class="text-right">Kept as is</th>
                        <th scope="col" class="text-right">Skipped</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.key">
                        <td>{{ row.label }}</td>
                        <td class="text-right tabular-nums">{{ row.read.toLocaleString('en-PH') }}</td>
                        <td class="text-right tabular-nums">{{ row.written.toLocaleString('en-PH') }}</td>
                        <td class="text-right tabular-nums">{{ row.kept.toLocaleString('en-PH') }}</td>
                        <td class="text-right tabular-nums" :class="row.skipped ? 'font-semibold text-danger' : ''">{{ row.skipped.toLocaleString('en-PH') }}</td>
                    </tr>
                </tbody>
            </Table>

            <Alert v-if="shown.warnings.length" tone="warning" :title="`${shown.warnings.length} note(s) to read`" class="mb-4">
                <ul class="list-disc space-y-1 pl-5">
                    <li v-for="(warning, index) in shown.warnings.slice(0, 25)" :key="index">{{ warning }}</li>
                </ul>
                <p v-if="shown.warnings.length > 25" class="mt-2">{{ shown.warnings.length - 25 }} more not shown.</p>
            </Alert>
        </template>
        <p v-else class="text-sm text-text-muted">No sync has been run yet.</p>
    </StaffLayout>
</template>
