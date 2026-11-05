<script setup>
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import StaffLayout from '@/Layouts/StaffLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import Table from '@/Components/UI/Table.vue';
import Alert from '@/Components/Feedback/Alert.vue';
import { confirm } from '@/Composables/useDialog';

const props = defineProps({
    enabled: { type: Boolean, required: true },
    connected: { type: Boolean, required: true },
    error: { type: String, default: null },
    steps: { type: Array, required: true },
    last: { type: Object, default: null },
});

const BASE = '/admin/legacy-sync';

// ─── Calls ────────────────────────────────────────────────────────────────
// Small JSON calls, one after another: none of them runs long.
const xsrf = () => decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');

const call = async (path, body = {}) => {
    const response = await fetch(`${BASE}/${path}`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
        body: JSON.stringify(body),
    });
    const data = await response.json().catch(() => ({}));

    if (!response.ok) throw new Error(data.message ?? 'The request failed. Reload the page and try again.');

    return data;
};

// ─── Preview ──────────────────────────────────────────────────────────────
const preview = ref(null);
const previewing = ref(false);
const message = ref(null);

const runPreview = async () => {
    message.value = null;
    previewing.value = true;

    try {
        preview.value = (await call('preview')).rows;
    } catch (e) {
        message.value = e.message;
    } finally {
        previewing.value = false;
    }
};

const totals = computed(() => ({
    add: (preview.value ?? []).reduce((sum, row) => sum + (row.add ?? 0), 0),
    update: (preview.value ?? []).reduce((sum, row) => sum + (row.update ?? 0), 0),
}));

// ─── Apply, step by step ──────────────────────────────────────────────────
const progress = ref([]); // [{ key, label, state: 'pending'|'running'|'done'|'failed', result }]
const running = ref(false);
const result = ref(null);

const apply = async () => {
    const confirmed = await confirm({
        title: 'Apply the sync?',
        text: preview.value
            ? `About ${totals.value.add.toLocaleString('en-PH')} record(s) will be added and ${totals.value.update.toLocaleString('en-PH')} updated. Anything edited here is kept and nothing is removed. Keep this page open until it finishes.`
            : 'New and changed legacy records are brought in. Anything edited here is kept and nothing is removed. Keep this page open until it finishes.',
        confirmText: 'Apply sync',
        tone: 'primary',
    });

    if (!confirmed) return;

    message.value = null;
    result.value = null;
    running.value = true;
    progress.value = props.steps.map((step) => ({ ...step, state: 'pending', result: null }));

    let token = null;

    try {
        token = (await call('begin')).token;

        for (const entry of progress.value) {
            entry.state = 'running';
            try {
                entry.result = (await call('step', { token, step: entry.key })).result;
                entry.state = 'done';
            } catch (e) {
                entry.state = 'failed';
                throw e;
            }
        }

        result.value = (await call('finish', { token })).result;
        token = null;
        await runPreview();
    } catch (e) {
        message.value = e.message;
        if (token) await call('abort', { token }).catch(() => {});
    } finally {
        running.value = false;
    }
};

// ─── Display ──────────────────────────────────────────────────────────────
const shown = computed(() => result.value ?? props.last);
const warnings = computed(() => shown.value?.warnings ?? []);
const formatWhen = (iso) => (iso ? new Date(iso).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' }) : '');
const n = (value) => (value === null || value === undefined ? '—' : value.toLocaleString('en-PH'));
const stateLabel = { pending: 'Waiting', running: 'Working…', done: 'Done', failed: 'Stopped' };
</script>

<template>
    <Head title="Sync from legacy" />

    <StaffLayout>
        <PageHeader
            title="Sync from legacy"
            description="Bring the legacy database's latest records into this system while the old app is still in use."
            :breadcrumbs="[{ label: 'Dashboard', href: '/dashboard' }, { label: 'Sync from legacy' }]"
        >
            <template v-if="enabled && connected" #actions>
                <Button variant="outline" :loading="previewing" :disabled="running" @click="runPreview">
                    <Icon name="eye" size="sm" />
                    Preview changes
                </Button>
                <Button :loading="running" :disabled="previewing" @click="apply">
                    <Icon name="refresh" size="sm" />
                    {{ running ? 'Syncing…' : 'Apply sync' }}
                </Button>
            </template>
        </PageHeader>

        <Alert v-if="!enabled" tone="warning" title="Sync is switched off" class="mb-4">
            To use it, set <code>ETTS_LEGACY_SYNC=true</code> and the <code>LEGACY_DB_*</code> settings in the server's <code>.env</code>, then run
            <code>php artisan config:clear</code>. Switch it off again once ETTS is the live system.
        </Alert>
        <Alert v-else-if="error" tone="danger" title="Legacy database not reachable" class="mb-4">{{ error }}</Alert>

        <Alert tone="info" title="How it works" class="mb-4">
            It only reads the legacy database. A record is added when it is new and updated when the legacy app changed it after this system did. A record
            edited here is never overwritten, and a record deleted in the legacy app is reported, not removed. Preview first to see the numbers.
            The same thing runs from the command line: <code>php artisan etts:sync-legacy</code> (add <code>--apply</code> to write).
        </Alert>

        <Alert v-if="message" tone="danger" title="The sync stopped" class="mb-4">{{ message }}</Alert>

        <template v-if="progress.length">
            <h2 class="mb-2 text-sm font-semibold text-text">Progress</h2>
            <ul class="mb-6 divide-y divide-border rounded-lg border border-border bg-surface" aria-live="polite">
                <li v-for="entry in progress" :key="entry.key" class="flex items-center justify-between gap-3 px-4 py-2 text-sm">
                    <span>{{ entry.label }}</span>
                    <span class="flex items-center gap-2" :class="{ 'text-text-muted': entry.state === 'pending', 'text-success': entry.state === 'done', 'text-danger': entry.state === 'failed' }">
                        <template v-if="entry.state === 'done' && entry.result && entry.key !== 'reference'">
                            {{ n(entry.result.written) }} written · {{ n(entry.result.kept) }} kept<template v-if="entry.result.skipped"> · {{ n(entry.result.skipped) }} skipped</template>
                        </template>
                        <template v-else>{{ stateLabel[entry.state] }}</template>
                    </span>
                </li>
            </ul>
        </template>

        <template v-if="preview">
            <h2 class="mb-1 text-sm font-semibold text-text">What a sync would do</h2>
            <p class="mb-2 text-sm text-text-muted">An estimate; nothing has been written. A legacy row that cannot be linked is counted here but skipped when applied.</p>
            <Table class="mb-8">
                <thead>
                    <tr>
                        <th scope="col">Table</th>
                        <th scope="col" class="text-right">In legacy</th>
                        <th scope="col" class="text-right">Would add</th>
                        <th scope="col" class="text-right">Would update</th>
                        <th scope="col" class="text-right">Kept as is</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in preview" :key="row.key">
                        <td>{{ row.label }}</td>
                        <td class="text-right tabular-nums">{{ n(row.legacy) }}</td>
                        <td class="text-right tabular-nums">{{ n(row.add) }}</td>
                        <td class="text-right tabular-nums">{{ n(row.update) }}</td>
                        <td class="text-right tabular-nums">{{ n(row.keep) }}</td>
                    </tr>
                </tbody>
            </Table>
        </template>

        <template v-if="shown">
            <h2 class="mb-1 text-sm font-semibold text-text">{{ result ? 'This sync' : 'Last sync' }}</h2>
            <p class="mb-2 text-sm text-text-muted">{{ formatWhen(shown.at) }}<template v-if="shown.by"> · by {{ shown.by }}</template></p>

            <Alert v-if="warnings.length" tone="warning" :title="`${warnings.length} note(s) to read`" class="mb-4">
                <ul class="list-disc space-y-1 pl-5">
                    <li v-for="(warning, index) in warnings.slice(0, 25)" :key="index">{{ warning }}</li>
                </ul>
                <p v-if="warnings.length > 25" class="mt-2">{{ warnings.length - 25 }} more not shown.</p>
            </Alert>
        </template>
        <p v-else-if="enabled && !progress.length" class="text-sm text-text-muted">No sync has been run yet. Start with “Preview changes”.</p>
    </StaffLayout>
</template>
