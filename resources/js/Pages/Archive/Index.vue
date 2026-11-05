<script setup>
import { personName } from '@/Utils/text.js';
import { computed, reactive, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import StaffLayout from '@/Layouts/StaffLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/UI/Button.vue';
import Table from '@/Components/UI/Table.vue';
import Badge from '@/Components/UI/Badge.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Icon from '@/Components/UI/Icon.vue';
import Select from '@/Components/Forms/Select.vue';
import TimelineModal from '@/Components/Transactions/TimelineModal.vue';
import { formatDate } from '@/Utils/format.js';

const props = defineProps({
    records: { type: Object, required: true },
    filters: { type: Object, required: true },
    options: { type: Object, required: true },
    base: { type: String, default: '/admin/archive' },
    timelineBase: { type: String, default: '/admin/transactions' },
});

const filters = reactive({
    search: props.filters.search ?? '',
    reason: props.filters.reason ?? '',
    office: props.filters.office ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

const anyFilter = computed(() => Object.values(filters).some((value) => value !== ''));

let searchTimer;
watch(
    () => ({ ...filters }),
    (value, old) => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(
            () => {
                const query = Object.fromEntries(Object.entries(value).filter(([, v]) => v !== ''));
                router.get(props.base, query, { preserveState: true, preserveScroll: true, replace: true });
            },
            value.search !== old.search ? 300 : 0,
        );
    },
);

const clearFilters = () => Object.keys(filters).forEach((key) => (filters[key] = ''));

const reasonOptions = [{ value: '', label: 'All reasons' }, ...props.options.reasons];
const officeOptions = [{ value: '', label: 'All offices' }, ...props.options.offices];

const viewing = ref(null);

const formatWhen = (iso) =>
    iso ? new Date(iso).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' }) : '';

const inputClass =
    'block w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-sm text-text shadow-xs focus:border-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring';
</script>

<template>
    <Head title="Deleted records" />

    <StaffLayout>
        <PageHeader
            title="Deleted records"
            description="Transactions that were deleted. They are kept here with who deleted them, when and why."
            :breadcrumbs="[{ label: 'Dashboard', href: '/dashboard' }, { label: 'Deleted records' }]"
        />

        <div class="mb-4 grid gap-3 surface-raised p-4 sm:grid-cols-2 lg:grid-cols-5">
            <div class="relative">
                <label for="search" class="sr-only">Search</label>
                <Icon name="search" size="sm" class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-text-muted" />
                <input id="search" v-model="filters.search" type="search" placeholder="Name, OR number or ID" :class="[inputClass, 'pl-9']" />
            </div>
            <div>
                <label for="reason" class="sr-only">Reason</label>
                <Select id="reason" v-model="filters.reason" :options="reasonOptions" />
            </div>
            <div v-if="options.offices.length">
                <label for="office" class="sr-only">Office</label>
                <Select id="office" v-model="filters.office" :options="officeOptions" />
            </div>
            <div class="flex items-center gap-2 lg:col-span-2">
                <label for="from" class="sr-only">Deleted from</label>
                <input id="from" v-model="filters.from" type="date" :class="inputClass" />
                <span class="text-text-muted" aria-hidden="true">–</span>
                <label for="to" class="sr-only">Deleted to</label>
                <input id="to" v-model="filters.to" type="date" :class="inputClass" />
            </div>
            <div v-if="anyFilter" class="sm:col-span-2 lg:col-span-5">
                <Button variant="ghost" size="sm" @click="clearFilters">
                    <Icon name="close" size="sm" />
                    Clear filters
                </Button>
            </div>
        </div>

        <Table :empty="records.data.length === 0">
            <template #empty>
                <EmptyState icon="archive" title="No deleted records" description="Nothing matches. Try another search or clear the filters." />
            </template>
            <thead>
                <tr>
                    <th scope="col">ID</th>
                    <th scope="col">Applicant</th>
                    <th scope="col">Type</th>
                    <th v-if="options.offices.length" scope="col">Office</th>
                    <th scope="col">Deleted</th>
                    <th scope="col">Reason</th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="record in records.data" :key="record.id">
                    <td class="whitespace-nowrap text-text-muted">#{{ record.id }}</td>
                    <td class="font-medium">
                        {{ personName(record.name) }}
                        <div class="text-xs font-normal text-text-muted">Filed {{ formatDate(record.transaction_date) }} · was {{ record.status }}</div>
                    </td>
                    <td class="text-sm">{{ record.type }}</td>
                    <td v-if="options.offices.length" class="whitespace-nowrap text-sm">{{ record.office }}</td>
                    <td class="text-sm whitespace-nowrap">
                        {{ formatWhen(record.deleted_at) }}
                        <div class="text-xs text-text-muted">by {{ record.deleted_by }}</div>
                    </td>
                    <td class="text-sm">
                        <Badge>{{ record.reason }}</Badge>
                        <div v-if="record.note" class="mt-1 max-w-xs truncate text-xs text-text-muted" :title="record.note">{{ record.note }}</div>
                    </td>
                    <td class="text-right whitespace-nowrap">
                        <Button variant="ghost" size="sm" :aria-label="`History of deleted transaction ${record.id}`" @click="viewing = { id: record.id, name: record.name, type: record.type, status: null }">
                            <Icon name="clock" size="sm" />
                            History
                        </Button>
                    </td>
                </tr>
            </tbody>
        </Table>

        <p class="mt-3 text-sm text-text-muted">{{ records.total.toLocaleString('en-PH') }} deleted records</p>
        <Pagination :prev-url="records.prev_page_url" :next-url="records.next_page_url" :current-page="records.current_page" :last-page="records.last_page" />

        <TimelineModal :record="viewing" :base="timelineBase" @close="viewing = null" />
    </StaffLayout>
</template>
