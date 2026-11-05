<script setup>
import { reactive, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import StaffLayout from '@/Layouts/StaffLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import Select from '@/Components/Forms/Select.vue';
import StatCard from '@/Components/Dashboard/StatCard.vue';
import TransactionStatusBadge from '@/Components/Transactions/TransactionStatusBadge.vue';
import { formatDate } from '@/Utils/format.js';

const props = defineProps({
    filters: { type: Object, required: true },
    report: { type: Object, required: true },
    eligibilities: { type: Array, required: true },
});

const filters = reactive({ from: props.filters.from ?? '', to: props.filters.to ?? '', eligibility: props.filters.eligibility ?? '' });
const options = [{ value: '', label: 'All eligibilities' }, ...props.eligibilities.map((code) => ({ value: code, label: code }))];

watch(() => filters.eligibility, () => apply());

const apply = () => {
    const query = Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== ''));
    router.get('/admin/audit/duplicates', query, { preserveScroll: true });
};
const clear = () => {
    Object.keys(filters).forEach((key) => (filters[key] = ''));
    router.get('/admin/audit/duplicates');
};

const inputClass =
    'block w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-sm text-text shadow-xs focus:border-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring';
const anyFilter = () => Object.values(filters).some((value) => value !== '');
</script>

<template>
    <Head title="Duplicate audit" />

    <StaffLayout>
        <PageHeader
            title="Duplicate audit"
            description="Applicants who already hold more than one approved record for the same eligibility. An applicant may hold only one. New duplicates are blocked when they are entered; this finds any already on file."
            :breadcrumbs="[{ label: 'Dashboard', href: '/dashboard' }, { label: 'Duplicate audit' }]"
        />

        <form class="surface-raised mb-6 flex flex-wrap items-end gap-4 p-4" @submit.prevent="apply">
            <div>
                <label for="from" class="mb-1 block text-xs font-semibold text-text-muted uppercase">Decided from</label>
                <input id="from" v-model="filters.from" type="date" :class="inputClass" />
            </div>
            <div>
                <label for="to" class="mb-1 block text-xs font-semibold text-text-muted uppercase">to</label>
                <input id="to" v-model="filters.to" type="date" :class="inputClass" />
            </div>
            <div class="w-48">
                <label for="eligibility" class="mb-1 block text-xs font-semibold text-text-muted uppercase">Eligibility</label>
                <Select id="eligibility" v-model="filters.eligibility" :options="options" />
            </div>
            <Button type="submit">Apply</Button>
            <Button v-if="anyFilter()" type="button" variant="ghost" @click="clear"><Icon name="close" size="sm" />Clear</Button>
        </form>

        <div class="mb-6 grid gap-4 sm:grid-cols-2">
            <StatCard label="Applicants with duplicates" :value="report.groupCount" icon="users" :tone="report.groupCount ? 'danger' : 'success'" :note="report.groupCount ? 'need a decision' : 'none found'" />
            <StatCard label="Records involved" :value="report.recordCount" icon="clipboard" tone="info" note="approved records in those groups" />
        </div>

        <div v-if="report.groupCount === 0" class="surface-raised flex flex-col items-center gap-2 p-10 text-center">
            <span class="flex size-14 items-center justify-center rounded-full bg-success-bg text-success"><Icon name="checkCircle" /></span>
            <h2 class="text-lg font-semibold text-text">No duplicate approvals</h2>
            <p class="max-w-md text-sm text-text-muted">Every applicant holds at most one approved record per eligibility{{ anyFilter() ? ' within this selection' : '' }}.</p>
        </div>

        <ul v-else class="space-y-4">
            <li v-for="group in report.groups" :key="`${group.lname}-${group.fname}-${group.mname}-${group.eligCode}`" class="surface-raised overflow-hidden">
                <header class="flex flex-wrap items-center justify-between gap-2 border-b border-border bg-danger-bg/50 px-5 py-3">
                    <h2 class="text-base font-semibold text-text">{{ group.lname }}, {{ group.fname }} {{ group.mname }}</h2>
                    <span class="flex items-center gap-2 text-sm">
                        <span class="rounded-full bg-surface px-2.5 py-0.5 font-semibold text-primary">{{ group.eligCode }}</span>
                        <span class="rounded-full bg-danger px-2.5 py-0.5 text-xs font-semibold text-white">{{ group.approvedCount }} approved</span>
                    </span>
                </header>
                <ul class="divide-y divide-border">
                    <li v-for="record in group.records" :key="record.id" class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm">
                        <span>
                            <Link :href="`/admin/transactions/${record.id}/edit`" class="font-semibold text-primary hover:underline">#{{ record.id }}</Link>
                            <span class="ml-2 text-text-muted">{{ record.officeCode }}</span>
                            <span v-if="record.officialReceipt" class="ml-2 text-text-muted">OR {{ record.officialReceipt }}</span>
                        </span>
                        <span class="flex items-center gap-3 text-text-muted">
                            <span>Filed {{ formatDate(record.transactionDate?.slice(0, 10)) }}</span>
                            <span v-if="record.dateCompleted">Decided {{ formatDate(record.dateCompleted) }}</span>
                            <TransactionStatusBadge :status="record.status" />
                        </span>
                    </li>
                </ul>
            </li>
        </ul>
    </StaffLayout>
</template>
