<script setup>
import { personName } from '@/Utils/text.js';
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import StaffLayout from '@/Layouts/StaffLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import Alert from '@/Components/Feedback/Alert.vue';
import HeroBanner from '@/Components/Dashboard/HeroBanner.vue';
import StatCard from '@/Components/Dashboard/StatCard.vue';
import ChartCard from '@/Components/Dashboard/ChartCard.vue';
import ColumnChart from '@/Components/Charts/ColumnChart.vue';
import { formatNumber } from '@/Components/Charts/palette.js';
import { formatDate } from '@/Utils/format.js';

const props = defineProps({
    office: { type: Object, default: null },
    stats: { type: Object, default: () => ({}) },
    chart: { type: Object, default: () => ({ labels: [], spels: [], issuance: [] }) },
    matrix: { type: Array, default: () => [] },
    aging: { type: Array, default: () => [] },
});

const user = computed(() => usePage().props.auth.user);
const hour = Number(new Date().toLocaleString('en-GB', { hour: '2-digit', hour12: false, timeZone: 'Asia/Manila' }));
const greeting = hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening';
const today = new Date().toLocaleDateString('en-PH', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric', timeZone: 'Asia/Manila' });

const series = computed(() => [
    { name: 'Special eligibility', values: props.chart.spels, color: 'var(--color-chart-1)' },
    { name: 'Certification and authentication', values: props.chart.issuance, color: 'var(--color-chart-2)' },
]);

const cards = computed(() => [
    { label: 'To forward', value: props.stats.pending_forward ?? 0, icon: 'upload', tone: 'warning', note: 'submitted, not yet sent up', href: '/fo/transactions?status=submitted' },
    { label: 'Returned', value: props.stats.returned ?? 0, icon: 'refresh', tone: 'danger', note: 'sent back for correction', href: '/fo/transactions?board=history&status=Returned' },
    { label: 'With the Regional Office', value: props.stats.processing_ro ?? 0, icon: 'clock', tone: 'info', note: 'forwarded or being processed', href: '/fo/transactions' },
    { label: 'Ready for release', value: props.stats.ready_release ?? 0, icon: 'checkCircle', tone: 'success', note: 'completed, for the client', href: '/fo/transactions?status=Completed' },
]);
</script>

<template>
    <Head title="Dashboard" />

    <StaffLayout>
        <Alert v-if="!office" tone="warning">
            Your account is not mapped to a field office yet, so there is nothing to show. Please ask your administrator to link your IMIS account to your office.
        </Alert>

        <template v-else>
            <HeroBanner
                :eyebrow="today"
                :title="`${greeting}, ${user?.name?.split(' ')[0] ?? 'there'}`"
                :subtitle="`${office.name}. ${formatNumber(stats.pending_forward)} application(s) are ready to be forwarded to the Regional Office.`"
            >
                <template #actions>
                    <Button variant="secondary" href="/fo/transactions/create?type=Special%20Eligibility%20Application">
                        <Icon name="plus" size="sm" />
                        New application
                    </Button>
                    <Button variant="outline" class="!border-white/60 !bg-transparent !text-white hover:!bg-white/10" href="/fo/transmittals/create">
                        <Icon name="envelope" size="sm" />
                        New transmittal
                    </Button>
                </template>
            </HeroBanner>

            <div class="relative -mt-10 grid gap-4 px-2 sm:grid-cols-2 sm:px-4 xl:grid-cols-4">
                <StatCard v-for="card in cards" :key="card.label" v-bind="card" />
            </div>

            <div class="mt-6 grid gap-6 xl:grid-cols-3">
                <div class="xl:col-span-2">
                    <ChartCard title="Filed in the last six months" description="Applications and requests filed at your office.">
                        <ColumnChart :labels="chart.labels" :series="series" stacked :height="250" label="Filed in the last six months" />
                    </ChartCard>
                </div>

                <ChartCard title="Where things stand" description="By type and stage.">
                    <p v-if="matrix.length === 0" class="py-6 text-center text-sm text-text-muted">Nothing filed yet.</p>
                    <div v-else class="-mx-2 overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="table-head">
                                <tr>
                                    <th scope="col" class="px-2 py-2">Type</th>
                                    <th scope="col" class="px-2 py-2 text-right" title="Submitted">Sub.</th>
                                    <th scope="col" class="px-2 py-2 text-right" title="Forwarded">Fwd.</th>
                                    <th scope="col" class="px-2 py-2 text-right" title="Received">Rec.</th>
                                    <th scope="col" class="px-2 py-2 text-right" title="Completed">Done</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                <tr v-for="row in matrix" :key="row.type">
                                    <th scope="row" class="px-2 py-2 text-left text-xs font-medium text-text">{{ row.type }}</th>
                                    <td class="px-2 py-2 text-right tabular-nums">{{ row.submitted }}</td>
                                    <td class="px-2 py-2 text-right tabular-nums">{{ row.forwarded }}</td>
                                    <td class="px-2 py-2 text-right tabular-nums">{{ row.received }}</td>
                                    <td class="px-2 py-2 text-right tabular-nums">{{ row.completed }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </ChartCard>
            </div>

            <div class="mt-6">
                <ChartCard title="Waiting to be forwarded" description="Applications submitted more than ten days ago and still not sent to the Regional Office, oldest first.">
                    <div v-if="aging.length === 0" class="flex items-center gap-3 py-4 text-sm text-text-muted">
                        <span class="flex size-9 items-center justify-center rounded-full bg-success-bg text-success"><Icon name="checkCircle" size="sm" /></span>
                        Nothing is overdue. Good work.
                    </div>
                    <ul v-else class="divide-y divide-border text-sm">
                        <li v-for="row in aging" :key="row.id" class="flex items-center justify-between gap-3 py-2.5">
                            <span class="min-w-0">
                                <Link :href="`/fo/transactions/${row.id}/edit`" class="block truncate font-medium text-primary hover:underline">#{{ row.id }} {{ personName(row.name) }}</Link>
                                <span class="text-xs text-text-muted">{{ row.type }} · filed {{ formatDate(row.date) }}</span>
                            </span>
                            <span class="shrink-0 rounded-full bg-warning-bg px-2.5 py-0.5 text-xs font-semibold text-warning">{{ row.days }} days</span>
                        </li>
                    </ul>
                </ChartCard>
            </div>
        </template>
    </StaffLayout>
</template>
