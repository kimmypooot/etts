<script setup>
import { computed, reactive, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import StaffLayout from '@/Layouts/StaffLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/UI/Button.vue';
import Table from '@/Components/UI/Table.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Icon from '@/Components/UI/Icon.vue';
import Select from '@/Components/Forms/Select.vue';
import TransmittalStatusBadge from '@/Components/Transmittals/TransmittalStatusBadge.vue';

const props = defineProps({
    transmittals: { type: Object, required: true },
    filters: { type: Object, required: true },
    counts: { type: Object, required: true },
    options: { type: Object, required: true },
    portal: { type: String, default: 'esd' },
    base: { type: String, default: '/admin/transmittals' },
    canCreate: { type: Boolean, default: false },
});

const directions =
    props.portal === 'fo'
        ? [
              { key: 'FO_TO_RO', label: 'Sent to the Regional Office' },
              { key: 'ESD_TO_FO', label: 'Received from ESD' },
          ]
        : [
              { key: 'ESD_TO_FO', label: 'To field offices' },
              { key: 'FO_TO_RO', label: 'From field offices' },
          ];

const filters = reactive({
    search: props.filters.search ?? '',
    status: props.filters.status ?? '',
    office: props.filters.office ?? '',
});

const anyFilter = computed(() => Object.values(filters).some((value) => value !== ''));

let timer;
watch(
    () => ({ ...filters }),
    (value, old) => {
        clearTimeout(timer);
        timer = setTimeout(
            () => {
                const query = Object.fromEntries(Object.entries({ direction: props.filters.direction, ...value }).filter(([, v]) => v !== ''));
                router.get(props.base, query, { preserveState: true, preserveScroll: true, replace: true });
            },
            value.search !== old.search ? 300 : 0,
        );
    },
);

const switchDirection = (direction) => router.get(props.base, { direction });
const clearFilters = () => Object.keys(filters).forEach((key) => (filters[key] = ''));

const statusOptions = [{ value: '', label: 'All statuses' }, ...props.options.statuses];
const officeOptions = [{ value: '', label: 'All offices' }, ...props.options.offices];

const formatWhen = (iso) =>
    iso ? new Date(iso).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' }) : '';

const inputClass =
    'block w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-sm text-text shadow-xs focus:border-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring';
</script>

<template>
    <Head title="Transmittals" />

    <StaffLayout>
        <PageHeader
            title="Transmittals"
            description="Batches of documents sent between ESD and the field offices, with their printable routing slips."
            :breadcrumbs="[{ label: 'Dashboard', href: '/dashboard' }, { label: 'Transmittals' }]"
        >
            <template v-if="canCreate" #actions>
                <Button :href="`${base}/create`">
                    <Icon name="plus" size="sm" />
                    New transmittal
                </Button>
            </template>
        </PageHeader>

        <div class="mb-4 flex gap-1 border-b border-border" role="tablist" aria-label="Direction">
            <button
                v-for="direction in directions"
                :key="direction.key"
                type="button"
                role="tab"
                :aria-selected="props.filters.direction === direction.key"
                class="-mb-px border-b-2 px-4 py-2 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                :class="props.filters.direction === direction.key ? 'border-primary text-primary' : 'border-transparent text-text-muted hover:text-text'"
                @click="switchDirection(direction.key)"
            >
                {{ direction.label }}
                <span class="ml-1 rounded-full bg-surface-muted px-2 py-0.5 text-xs">{{ counts[direction.key].toLocaleString('en-PH') }}</span>
            </button>
        </div>

        <div class="mb-4 grid gap-3 surface-raised p-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="relative">
                <label for="search" class="sr-only">Search</label>
                <Icon name="search" size="sm" class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-text-muted" />
                <input id="search" v-model="filters.search" type="search" placeholder="Number or remarks" :class="[inputClass, 'pl-9']" />
            </div>
            <div>
                <label for="status" class="sr-only">Status</label>
                <Select id="status" v-model="filters.status" :options="statusOptions" />
            </div>
            <div v-if="portal === 'esd'">
                <label for="office" class="sr-only">Office</label>
                <Select id="office" v-model="filters.office" :options="officeOptions" />
            </div>
            <div v-if="anyFilter">
                <Button variant="ghost" size="sm" @click="clearFilters">
                    <Icon name="close" size="sm" />
                    Clear filters
                </Button>
            </div>
        </div>

        <Table :empty="transmittals.data.length === 0">
            <template #empty>
                <EmptyState icon="envelope" title="No transmittals" description="Nothing matches. Try another search or clear the filters." />
            </template>
            <thead>
                <tr>
                    <th scope="col">Number</th>
                    <th scope="col">{{ props.filters.direction === 'FO_TO_RO' ? 'From' : 'To' }}</th>
                    <th scope="col">Rows</th>
                    <th scope="col">Status</th>
                    <th scope="col">Created</th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in transmittals.data" :key="row.id">
                    <td class="font-medium whitespace-nowrap">
                        <Link :href="`${base}/${row.id}`" class="text-primary hover:underline">{{ row.number }}</Link>
                    </td>
                    <td class="text-sm">{{ row.office?.name ?? row.office?.code }}</td>
                    <td class="text-sm">{{ row.items_count }}</td>
                    <td><TransmittalStatusBadge :status="row.status" /></td>
                    <td class="text-sm whitespace-nowrap">
                        {{ formatWhen(row.created_at) }}
                        <div class="text-xs text-text-muted">{{ row.created_by }}</div>
                    </td>
                    <td class="text-right whitespace-nowrap">
                        <Button variant="ghost" size="sm" :href="`${base}/${row.id}`">Open</Button>
                        <Button variant="ghost" size="sm" :href="`${base}/${row.id}/slip`" :aria-label="`Print slip of ${row.number}`">
                            <Icon name="printer" size="sm" />
                            Slip
                        </Button>
                    </td>
                </tr>
            </tbody>
        </Table>

        <p class="mt-3 text-sm text-text-muted">{{ transmittals.total.toLocaleString('en-PH') }} transmittals</p>
        <Pagination :prev-url="transmittals.prev_page_url" :next-url="transmittals.next_page_url" :current-page="transmittals.current_page" :last-page="transmittals.last_page" />
    </StaffLayout>
</template>
