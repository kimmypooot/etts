<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import StaffLayout from '@/Layouts/StaffLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import Modal from '@/Components/Feedback/Modal.vue';
import Alert from '@/Components/Feedback/Alert.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Select from '@/Components/Forms/Select.vue';
import StatCard from '@/Components/Dashboard/StatCard.vue';
import ChartCard from '@/Components/Dashboard/ChartCard.vue';
import ColumnChart from '@/Components/Charts/ColumnChart.vue';
import DonutChart from '@/Components/Charts/DonutChart.vue';
import RankedBars from '@/Components/Charts/RankedBars.vue';
import TransactionStatusBadge from '@/Components/Transactions/TransactionStatusBadge.vue';
import { useAuthorization } from '@/Composables/useAuthorization';
import { formatNumber } from '@/Components/Charts/palette.js';
import { formatDate } from '@/Utils/format.js';

const props = defineProps({
    category: { type: String, required: true },
    filters: { type: Object, required: true },
    report: { type: Object, required: true },
    offices: { type: Array, required: true },
});

const { can } = useAuthorization();
// As in legacy, the report prints as the page shows it (the browser's print dialog also saves a PDF).
const printReport = () => window.print();

const spels = computed(() => props.category === 'spels');

const categories = [
    { key: 'spels', label: 'Special eligibility', description: 'Applications by eligibility', icon: 'shield' },
    { key: 'coe_auth', label: 'Certifications and authentications', description: 'Requests by type', icon: 'documentText' },
];

// ─── Range and filters ────────────────────────────────────────────────────
const range = reactive({ from: props.filters.from, to: props.filters.to, office: props.filters.office ?? '' });

const iso = (date) => date.toLocaleDateString('en-CA', { timeZone: 'Asia/Manila' });
const today = () => new Date(new Date().toLocaleString('en-US', { timeZone: 'Asia/Manila' }));
const presets = [
    { key: 'month', label: 'This month', range: () => { const t = today(); return [new Date(t.getFullYear(), t.getMonth(), 1), t]; } },
    { key: 'last', label: 'Last month', range: () => { const t = today(); return [new Date(t.getFullYear(), t.getMonth() - 1, 1), new Date(t.getFullYear(), t.getMonth(), 0)]; } },
    { key: 'quarter', label: 'This quarter', range: () => { const t = today(); return [new Date(t.getFullYear(), Math.floor(t.getMonth() / 3) * 3, 1), t]; } },
    { key: 'year', label: 'This year', range: () => { const t = today(); return [new Date(t.getFullYear(), 0, 1), t]; } },
    { key: '12m', label: 'Last 12 months', range: () => { const t = today(); return [new Date(t.getFullYear(), t.getMonth() - 11, 1), t]; } },
];

const activePreset = computed(() => presets.find((preset) => {
    const [from, to] = preset.range();

    return iso(from) === props.filters.from && iso(to) === props.filters.to;
})?.key);

const query = (extra = {}) => Object.fromEntries(Object.entries({ category: props.category, from: range.from, to: range.to, office: range.office, ...extra }).filter(([, v]) => v !== '' && v != null));
const apply = (extra = {}) => router.get('/admin/statistics', query(extra), { preserveScroll: true, preserveState: false });
const usePreset = (preset) => {
    const [from, to] = preset.range();
    range.from = iso(from);
    range.to = iso(to);
    apply();
};
const exportUrl = computed(() => `/admin/statistics/export?${new URLSearchParams(query())}`);

watch(() => range.office, () => apply());

// ─── Headline figures ─────────────────────────────────────────────────────
const totals = computed(() => props.report.totals);
const rate = (part, whole) => (whole > 0 ? Math.round((part / whole) * 100) : null);
const decided = computed(() => (totals.value.approved ?? 0) + (totals.value.disapproved ?? 0) + (totals.value.returned ?? 0));

const cards = computed(() =>
    spels.value
        ? [
              { label: 'Filed', value: totals.value.submitted, icon: 'clipboard', tone: 'primary', note: 'in this period' },
              { label: 'Approved', value: totals.value.approved, icon: 'checkCircle', tone: 'success', note: decided.value ? `${rate(totals.value.approved, decided.value)}% of decisions` : 'no decisions yet' },
              { label: 'Disapproved or returned', value: (totals.value.disapproved ?? 0) + (totals.value.returned ?? 0), icon: 'xCircle', tone: 'danger', note: 'not granted' },
              { label: 'Waiting for a decision', value: totals.value.pending, icon: 'clock', tone: 'warning', note: 'received, not yet decided' },
          ]
        : [
              { label: 'Filed', value: totals.value.filed, icon: 'clipboard', tone: 'primary', note: 'in this period' },
              { label: 'Completed', value: totals.value.completed, icon: 'checkCircle', tone: 'info', note: totals.value.filed ? `${rate(totals.value.completed, totals.value.filed)}% of filed` : '' },
              { label: 'Released', value: totals.value.released, icon: 'envelope', tone: 'success', note: 'handed to the client' },
              { label: 'Still pending', value: totals.value.pending, icon: 'clock', tone: 'warning', note: `${formatNumber(totals.value.cancelled)} cancelled` },
          ],
);

// ─── Charts ───────────────────────────────────────────────────────────────
const labels = computed(() => props.report.rows.map((row) => row.group));
const chartSeries = computed(() => {
    const pick = spels.value
        ? [['submitted', 'Filed', 'var(--color-chart-1)'], ['approved', 'Approved', 'var(--color-chart-3)'], ['disapproved', 'Disapproved', 'var(--color-chart-6)'], ['returned', 'Returned', 'var(--color-chart-5)']]
        : [['filed', 'Filed', 'var(--color-chart-1)'], ['completed', 'Completed', 'var(--color-chart-4)'], ['released', 'Released', 'var(--color-chart-3)'], ['cancelled', 'Cancelled', 'var(--color-chart-6)']];

    return pick.map(([key, name, color]) => ({ key, name, color, values: props.report.rows.map((row) => row[key]) }));
});

const funnel = computed(() => [
    { label: 'Filed', value: totals.value.submitted ?? 0 },
    { label: 'Forwarded to the RO', value: totals.value.forwarded ?? 0 },
    { label: 'Received at ESD', value: totals.value.received ?? 0 },
    { label: 'Decided', value: decided.value },
]);
const sexSegments = computed(() => (props.report.sexes ?? []).map((row, i) => ({ label: row.label, value: row.value, color: ['var(--color-chart-1)', 'var(--color-chart-6)', 'var(--color-chart-neutral)'][i % 3] })));

// ─── Drill-down ───────────────────────────────────────────────────────────
const drill = reactive({ open: false, title: '', metric: '', group: null, page: 1, state: 'idle', data: [], total: 0, last: 1 });

const loadDrill = async () => {
    drill.state = 'loading';

    try {
        const response = await fetch(`/admin/statistics/details?${new URLSearchParams(query({ metric: drill.metric, group: drill.group ?? '', page: drill.page }))}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (!response.ok) throw new Error(String(response.status));
        const body = await response.json();
        Object.assign(drill, { data: body.data, total: body.total, last: body.last_page, state: 'idle' });
    } catch {
        drill.state = 'error';
    }
};

const openDrill = (metric, group = null) => {
    Object.assign(drill, { open: true, metric, group, page: 1, title: `${props.report.columns[metric]}${group ? ` · ${group}` : ' · all'}` });
    loadDrill();
};
const turn = (step) => {
    drill.page = Math.min(drill.last, Math.max(1, drill.page + step));
    loadDrill();
};
const onSelect = ({ group, index }) => openDrill(chartSeries.value[index].key, group);

const inputClass =
    'block w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-sm text-text shadow-xs focus:border-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring';
const officeOptions = [{ value: '', label: 'All offices' }, ...props.offices];
</script>

<template>
    <Head title="Statistics" />

    <StaffLayout>
        <PageHeader
            title="Statistics"
            description="How many requests came in, moved on and were decided, by type or by eligibility. Select a number or a bar to see the records behind it."
            :breadcrumbs="[{ label: 'Dashboard', href: '/dashboard' }, { label: 'Statistics' }]"
        >
            <template #actions>
                <Button v-if="can('transactions.export')" variant="outline" :href="exportUrl" external><Icon name="download" size="sm" />Export CSV</Button>
                <Button variant="outline" aria-label="Print or save report as PDF" @click="printReport"><Icon name="printer" size="sm" />Print</Button>
            </template>
        </PageHeader>

        <div class="mb-5 grid gap-3 sm:grid-cols-2" role="tablist" aria-label="Report">
            <button
                v-for="item in categories"
                :key="item.key"
                type="button"
                role="tab"
                :aria-selected="category === item.key"
                class="surface-raised surface-lift flex items-center gap-3 p-4 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                :class="category === item.key ? '!border-primary ring-1 ring-primary/30' : ''"
                @click="category !== item.key && apply({ category: item.key })"
            >
                <span class="flex size-10 shrink-0 items-center justify-center rounded-xl" :class="category === item.key ? 'bg-primary text-white' : 'bg-surface-muted text-text-muted'"><Icon :name="item.icon" size="sm" /></span>
                <span>
                    <span class="block text-sm font-semibold text-text">{{ item.label }}</span>
                    <span class="block text-xs text-text-muted">{{ item.description }}</span>
                </span>
            </button>
        </div>

        <form class="surface-raised mb-6 flex flex-wrap items-end gap-4 p-4" @submit.prevent="apply()">
            <div class="flex flex-wrap gap-1.5" role="group" aria-label="Quick ranges">
                <button
                    v-for="preset in presets"
                    :key="preset.key"
                    type="button"
                    class="rounded-full border px-3 py-1 text-xs font-semibold transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                    :class="activePreset === preset.key ? 'border-primary bg-primary text-white' : 'border-border bg-surface text-text-muted hover:border-primary hover:text-primary'"
                    :aria-pressed="activePreset === preset.key"
                    @click="usePreset(preset)"
                >
                    {{ preset.label }}
                </button>
            </div>
            <div>
                <label for="from" class="mb-1 block text-xs font-semibold text-text-muted uppercase">From</label>
                <input id="from" v-model="range.from" type="date" :class="inputClass" required />
            </div>
            <div>
                <label for="to" class="mb-1 block text-xs font-semibold text-text-muted uppercase">To</label>
                <input id="to" v-model="range.to" type="date" :class="inputClass" required />
            </div>
            <div class="w-44">
                <label for="office" class="mb-1 block text-xs font-semibold text-text-muted uppercase">Office</label>
                <Select id="office" v-model="range.office" :options="officeOptions" />
            </div>
            <Button type="submit">Apply</Button>
        </form>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard v-for="card in cards" :key="card.label" v-bind="card" />
        </div>

        <EmptyState v-if="report.rows.length === 0" class="mt-6" icon="chartBar" title="Nothing in this period" description="No records match this range and office. Try a wider range." />

        <template v-else>
            <div class="mt-6 grid gap-6 xl:grid-cols-3">
                <div class="xl:col-span-2">
                    <ChartCard :title="spels ? 'By eligibility' : 'By type'" description="Select a bar to list its records.">
                        <ColumnChart :labels="labels" :series="chartSeries" :label="spels ? 'Applications by eligibility' : 'Requests by type'" @select="onSelect" />
                    </ChartCard>
                </div>

                <ChartCard v-if="spels" title="The path of an application" description="How far the applications of this period got.">
                    <RankedBars :items="funnel" color="primary" />
                </ChartCard>
                <ChartCard v-else title="Released, by sex" description="Of the requests released in this period.">
                    <DonutChart :segments="sexSegments" center-label="released" label="Released by sex" />
                </ChartCard>
            </div>

            <ChartCard v-if="!spels" class="mt-6" title="Released, by action officer" description="Who handed over the most documents in this period.">
                <RankedBars :items="report.officers" color="success" empty-text="Nothing was released in this period." />
            </ChartCard>

            <section class="surface-raised mt-6 overflow-hidden" aria-labelledby="table-title">
                <h2 id="table-title" class="px-5 pt-5 pb-3 text-base font-semibold text-text">The numbers</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="table-head">
                            <tr>
                                <th scope="col" class="sticky left-0 bg-surface-muted px-5 py-2.5">{{ spels ? 'Eligibility' : 'Type' }}</th>
                                <th v-for="(label, key) in report.columns" :key="key" scope="col" class="px-4 py-2.5 text-right">{{ label }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr v-for="row in report.rows" :key="row.group" class="hover:bg-surface-muted/60">
                                <th scope="row" class="sticky left-0 bg-surface px-5 py-2.5 text-left font-medium text-text">{{ row.group }}</th>
                                <td v-for="(label, key) in report.columns" :key="key" class="px-4 py-2.5 text-right tabular-nums">
                                    <button
                                        v-if="row[key] > 0"
                                        type="button"
                                        class="rounded px-2 py-0.5 font-semibold text-primary underline-offset-2 hover:bg-info-bg hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                                        :aria-label="`${row[key]} ${label}, ${row.group}: show records`"
                                        @click="openDrill(key, row.group)"
                                    >{{ formatNumber(row[key]) }}</button>
                                    <span v-else class="px-2 text-text-muted">0</span>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot class="border-t-2 border-border bg-surface-muted font-semibold">
                            <tr>
                                <th scope="row" class="sticky left-0 bg-surface-muted px-5 py-2.5 text-left">Total</th>
                                <td v-for="(label, key) in report.columns" :key="key" class="px-4 py-2.5 text-right tabular-nums">
                                    <button type="button" class="rounded px-2 py-0.5 text-primary hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring" @click="openDrill(key)">{{ formatNumber(totals[key]) }}</button>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>
        </template>

        <Modal :open="drill.open" :title="drill.title" tone="primary" icon="chartBar" size="xl" @close="drill.open = false">
            <p v-if="drill.state === 'loading'" class="py-10 text-center text-sm text-text-muted" role="status">Loading records…</p>
            <Alert v-else-if="drill.state === 'error'" tone="danger">The records could not be loaded. Close this and try again.</Alert>
            <template v-else>
                <p class="mb-3 text-sm text-text-muted">{{ formatNumber(drill.total) }} record(s)</p>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="table-head">
                            <tr>
                                <th scope="col" class="px-3 py-2">ID</th>
                                <th scope="col" class="px-3 py-2">Applicant</th>
                                <th scope="col" class="px-3 py-2">{{ spels ? 'Eligibility' : 'Type' }}</th>
                                <th scope="col" class="px-3 py-2">Office</th>
                                <th scope="col" class="px-3 py-2">Filed</th>
                                <th scope="col" class="px-3 py-2">Completed</th>
                                <th scope="col" class="px-3 py-2">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr v-for="row in drill.data" :key="row.id">
                                <td class="px-3 py-2 whitespace-nowrap"><a :href="`/admin/transactions/${row.id}/edit`" class="font-medium text-primary hover:underline">#{{ row.id }}</a></td>
                                <td class="px-3 py-2 font-medium">{{ row.name }}</td>
                                <td class="px-3 py-2">{{ spels ? row.eligibility ?? '—' : row.type }}</td>
                                <td class="px-3 py-2">{{ row.office }}</td>
                                <td class="px-3 py-2 whitespace-nowrap">{{ formatDate(row.filed) }}</td>
                                <td class="px-3 py-2 whitespace-nowrap">{{ row.completed ? formatDate(row.completed) : '—' }}</td>
                                <td class="px-3 py-2"><TransactionStatusBadge :status="row.status" /></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="drill.last > 1" class="mt-4 flex items-center justify-between text-sm">
                    <Button variant="secondary" size="sm" :disabled="drill.page <= 1" @click="turn(-1)">Previous</Button>
                    <span class="text-text-muted">Page {{ drill.page }} of {{ drill.last }}</span>
                    <Button variant="secondary" size="sm" :disabled="drill.page >= drill.last" @click="turn(1)">Next</Button>
                </div>
            </template>
        </Modal>
    </StaffLayout>
</template>
