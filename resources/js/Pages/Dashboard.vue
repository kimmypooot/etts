<script setup>
import { computed, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import StaffLayout from '@/Layouts/StaffLayout.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import HeroBanner from '@/Components/Dashboard/HeroBanner.vue';
import StatCard from '@/Components/Dashboard/StatCard.vue';
import ChartCard from '@/Components/Dashboard/ChartCard.vue';
import SegmentedControl from '@/Components/Dashboard/SegmentedControl.vue';
import AreaChart from '@/Components/Charts/AreaChart.vue';
import DonutChart from '@/Components/Charts/DonutChart.vue';
import RankedBars from '@/Components/Charts/RankedBars.vue';
import AnimatedNumber from '@/Components/Charts/AnimatedNumber.vue';
import { formatNumber } from '@/Components/Charts/palette.js';
import { formatDate } from '@/Utils/format.js';

const props = defineProps({
    office: { type: Object, default: null },
    alertDays: { type: Object, required: true },
    kpis: { type: Array, required: true },
    trends: { type: Object, required: true },
    statusMix: { type: Array, required: true },
    byType: { type: Array, required: true },
    topOffices: { type: Array, required: true },
    topEligibilities: { type: Array, required: true },
    turnaround: { type: Object, required: true },
    attention: { type: Object, required: true },
    activity: { type: Array, required: true },
});

const user = computed(() => usePage().props.auth.user);

const hour = Number(new Date().toLocaleString('en-GB', { hour: '2-digit', hour12: false, timeZone: 'Asia/Manila' }));
const greeting = hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening';
const today = new Date().toLocaleDateString('en-PH', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric', timeZone: 'Asia/Manila' });

const kpi = (key) => props.kpis.find((item) => item.key === key);

const range = ref('monthly');
const rangeOptions = [
    { value: 'daily', label: '30 days' },
    { value: 'monthly', label: '12 months' },
];
const trend = computed(() => props.trends[range.value]);
const series = computed(() => [
    { name: 'Special eligibility', values: trend.value.spels, color: 'var(--color-chart-1)' },
    { name: 'Certification and authentication', values: trend.value.issuance, color: 'var(--color-chart-2)' },
]);

const typeItems = computed(() => [...props.byType].sort((a, b) => b.value - a.value).map((item) => ({ ...item, href: `/admin/transactions?board=history&type=${encodeURIComponent(item.label)}` })));
const backlog = computed(() =>
    props.attention.releaseBacklog.map((row) => ({
        label: row.office,
        value: row.count,
        hint: row.oldest ? `Oldest finished ${formatDate(row.oldest)}` : null,
        href: `/admin/transmittals/create?office=${row.office_id}`,
    })),
);

const open = computed(() => kpi('open')?.value ?? 0);
const awaiting = computed(() => kpi('awaiting_receipt')?.value ?? 0);

const formatWhen = (iso) =>
    iso ? new Date(iso).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' }) : '';
</script>

<template>
    <Head title="Dashboard" />

    <StaffLayout>
        <HeroBanner
            :eyebrow="today"
            :title="`${greeting}, ${user?.name?.split(' ')[0] ?? 'there'}`"
            :subtitle="`${formatNumber(open)} transactions are open and ${formatNumber(awaiting)} are waiting to be received at ESD.`"
        >
            <template #actions>
                <Button variant="secondary" href="/admin/transactions/create">
                    <Icon name="plus" size="sm" />
                    New transaction
                </Button>
                <Button variant="outline" class="!border-white/60 !bg-transparent !text-white hover:!bg-white/10" href="/admin/transmittals/create">
                    <Icon name="envelope" size="sm" />
                    New transmittal
                </Button>
            </template>
        </HeroBanner>

        <div class="relative -mt-10 grid gap-4 px-2 sm:grid-cols-2 sm:px-4 xl:grid-cols-3">
            <StatCard
                v-for="item in kpis"
                :key="item.key"
                :label="item.label"
                :value="item.value"
                :format="item.format ?? 'number'"
                :icon="item.icon"
                :tone="item.tone"
                :delta="item.delta"
                :show-delta="item.compare.startsWith('vs')"
                :note="item.compare"
                :spark="item.spark"
                :href="item.href"
            />
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-3">
            <div class="xl:col-span-2">
                <ChartCard title="Filings over time" description="Special eligibility applications against certifications and authentications.">
                    <template #actions>
                        <SegmentedControl v-model="range" :options="rangeOptions" label="Time range" />
                    </template>
                    <AreaChart :labels="trend.labels" :series="series" label="Transactions filed" />
                    <template #footer>{{ formatNumber(trend.total) }} transactions filed in this period. The last point is the current period so far.</template>
                </ChartCard>
            </div>
            <ChartCard title="Where everything stands" description="All transactions on file.">
                <DonutChart :segments="statusMix" center-label="transactions" label="Transactions by stage" />
            </ChartCard>
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-3">
            <ChartCard title="By type" description="Filed in the last 12 months.">
                <RankedBars :items="typeItems" color="primary" />
            </ChartCard>
            <ChartCard title="Busiest offices" description="Filed in the last 12 months.">
                <RankedBars :items="topOffices" color="info" />
            </ChartCard>
            <ChartCard title="Most applied-for eligibilities" description="Special eligibility, last 12 months.">
                <RankedBars :items="topEligibilities" color="success" />
            </ChartCard>
        </div>

        <h2 class="mt-10 mb-3 text-lg font-semibold text-text">Needs attention</h2>
        <div class="grid gap-6 lg:grid-cols-3">
            <ChartCard title="Waiting to be received" :description="`Forwarded applications ESD has not received. Flagged after ${alertDays.receipt} days.`">
                <p v-if="attention.awaitingReceipt.length === 0" class="py-6 text-center text-sm text-text-muted">Everything forwarded has been received.</p>
                <ul v-else class="divide-y divide-border text-sm">
                    <li v-for="row in attention.awaitingReceipt" :key="row.id" class="flex items-center justify-between gap-3 py-2">
                        <span class="min-w-0">
                            <span class="block truncate font-medium text-text">#{{ row.id }} {{ row.name }}</span>
                            <span class="text-xs text-text-muted">{{ row.office }} · sent {{ formatDate(row.since) }}</span>
                        </span>
                        <span
                            class="shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold"
                            :class="row.days >= alertDays.receipt ? 'bg-warning-bg text-warning' : 'bg-surface-muted text-text-muted'"
                        >{{ row.days }} {{ row.days === 1 ? 'day' : 'days' }}</span>
                    </li>
                </ul>
                <template #footer><Link href="/admin/transactions?status=forwarded" class="font-medium text-primary hover:underline">See all forwarded applications</Link></template>
            </ChartCard>

            <ChartCard title="Ready to release" description="Completed or approved, for each field office. Select one to build its transmittal.">
                <RankedBars :items="backlog" color="warning" empty-text="Nothing is waiting to be released." />
            </ChartCard>

            <ChartCard title="Drafts left open" :description="`Transmittals still in draft after ${alertDays.draft} days.`">
                <p v-if="attention.staleDrafts.length === 0" class="py-6 text-center text-sm text-text-muted">No stale drafts.</p>
                <ul v-else class="divide-y divide-border text-sm">
                    <li v-for="row in attention.staleDrafts" :key="row.id" class="flex items-center justify-between gap-3 py-2">
                        <span class="min-w-0">
                            <Link :href="`/admin/transmittals/${row.id}`" class="block truncate font-medium text-primary hover:underline">{{ row.number }}</Link>
                            <span class="text-xs text-text-muted">{{ row.office }}</span>
                        </span>
                        <span class="shrink-0 rounded-full bg-warning-bg px-2 py-0.5 text-xs font-semibold text-warning">{{ row.days }} days</span>
                    </li>
                </ul>
                <template #footer><Link href="/admin/transmittals" class="font-medium text-primary hover:underline">Open transmittals</Link></template>
            </ChartCard>
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-3">
            <ChartCard title="How long it takes" description="Average days from filing to completion, for what was completed in the last 90 days." class="lg:col-span-1">
                <div class="grid grid-cols-2 gap-4">
                    <div class="rounded-xl bg-info-bg p-4">
                        <p class="text-xs font-semibold tracking-wide text-primary uppercase">Special eligibility</p>
                        <p class="mt-1 text-3xl font-bold text-text">
                            <AnimatedNumber v-if="turnaround.spels.days !== null" :value="turnaround.spels.days" format="decimal" /><span v-else class="text-text-muted">—</span>
                        </p>
                        <p class="text-xs text-text-muted">days · {{ formatNumber(turnaround.spels.count) }} completed</p>
                    </div>
                    <div class="rounded-xl bg-success-bg p-4">
                        <p class="text-xs font-semibold tracking-wide text-success uppercase">Certification / authentication</p>
                        <p class="mt-1 text-3xl font-bold text-text">
                            <AnimatedNumber v-if="turnaround.issuance.days !== null" :value="turnaround.issuance.days" format="decimal" /><span v-else class="text-text-muted">—</span>
                        </p>
                        <p class="text-xs text-text-muted">days · {{ formatNumber(turnaround.issuance.count) }} completed</p>
                    </div>
                </div>
            </ChartCard>

            <div class="lg:col-span-2">
                <ChartCard title="Recent activity" description="The latest things done in the system.">
                    <template #actions><Link href="/admin/activity" class="text-sm font-medium text-primary hover:underline">View all</Link></template>
                    <p v-if="activity.length === 0" class="py-6 text-center text-sm text-text-muted">Nothing has happened yet.</p>
                    <ol v-else class="relative space-y-4 border-l border-border pl-5">
                        <li v-for="(entry, index) in activity" :key="index" class="relative">
                            <span class="absolute top-1.5 -left-[1.62rem] size-2.5 rounded-full border-2 border-surface bg-csc-blue-light" aria-hidden="true" />
                            <p class="text-sm text-text">
                                <span class="font-medium">{{ entry.who ?? 'unknown' }}</span>
                                <span class="text-text-muted"> · {{ entry.action }}</span>
                            </p>
                            <p v-if="entry.details" class="line-clamp-2 text-sm text-text-muted">{{ entry.details }}</p>
                            <p class="text-xs text-text-muted">{{ formatWhen(entry.at) }}</p>
                        </li>
                    </ol>
                </ChartCard>
            </div>
        </div>
    </StaffLayout>
</template>
