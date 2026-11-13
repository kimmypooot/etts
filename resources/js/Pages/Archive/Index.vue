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
import FilterBar from '@/Components/Forms/FilterBar.vue';
import FilterField from '@/Components/Forms/FilterField.vue';
import SearchInput from '@/Components/Forms/SearchInput.vue';
import DateRange from '@/Components/Forms/DateRange.vue';
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

</script>

<template>
    <Head title="Deleted records" />

    <StaffLayout>
        <PageHeader
            title="Deleted records"
            description="Transactions that were deleted. They are kept here with who deleted them, when and why."
            :breadcrumbs="[{ label: 'Dashboard', href: '/dashboard' }, { label: 'Deleted records' }]"
        />

        <FilterBar :active="anyFilter" @clear="clearFilters">
            <FilterField label="Search" for-id="search" grow>
                <SearchInput id="search" v-model="filters.search" placeholder="Name, OR number or ID" />
            </FilterField>
            <FilterField label="Reason" for-id="reason">
                <Select id="reason" v-model="filters.reason" :options="reasonOptions" />
            </FilterField>
            <FilterField v-if="options.offices.length" label="Office" for-id="office">
                <Select id="office" v-model="filters.office" :options="officeOptions" />
            </FilterField>
            <FilterField label="Deleted between" for-id="from" wide>
                <DateRange v-model:from="filters.from" v-model:to="filters.to" />
            </FilterField>
        </FilterBar>

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
                        <Button variant="ghost" size="sm" :aria-label="`History of deleted transaction ${record.id}`" title="History" @click="viewing = { id: record.id, name: record.name, type: record.type, status: null }">
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
