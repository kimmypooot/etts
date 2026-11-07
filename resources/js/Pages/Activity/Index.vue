<script setup>
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

const props = defineProps({
    entries: { type: Object, required: true },
    filters: { type: Object, required: true },
    actions: { type: Array, required: true },
});

const filters = reactive({
    search: props.filters.search ?? '',
    action: props.filters.action ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

const anyFilter = computed(() => Object.values(filters).some((value) => value !== ''));
const actionOptions = computed(() => [{ value: '', label: 'All actions' }, ...props.actions.map((value) => ({ value, label: value }))]);

const visit = (source = props.filters.source, values = filters) => {
    const query = Object.fromEntries(Object.entries({ source, ...values }).filter(([, v]) => v !== ''));
    router.get('/admin/activity', query, { preserveState: true, preserveScroll: true, replace: true });
};

let searchTimer;
watch(
    () => ({ ...filters }),
    (value, old) => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => visit(props.filters.source, value), value.search !== old.search ? 300 : 0);
    },
);

// Exports the tab and filters on screen, every page of them.
const exportUrl = computed(() => {
    const query = Object.fromEntries(Object.entries({ source: props.filters.source, ...filters }).filter(([, v]) => v !== ''));

    return `/admin/activity/export?${new URLSearchParams(query)}`;
});

const clearFilters =() => Object.keys(filters).forEach((key) => (filters[key] = ''));

const switchSource = (source) => {
    Object.keys(filters).forEach((key) => (filters[key] = ''));
    router.get('/admin/activity', { source });
};

const viewing = ref(null);

const formatWhen = (iso) =>
    iso ? new Date(iso).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' }) : '';

const inputClass =
    'block w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-sm text-text shadow-xs focus:border-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring';
</script>

<template>
    <Head title="Activity log" />

    <StaffLayout>
        <PageHeader
            title="Activity log"
            description="Who did what. “Current” is what this system records; “Legacy” is the history imported from the old system."
            :breadcrumbs="[{ label: 'Dashboard', href: '/dashboard' }, { label: 'Activity log' }]"
        >
            <template #actions>
                <Button variant="outline" :href="exportUrl" external>
                    <Icon name="download" size="sm" />
                    Export CSV
                </Button>
            </template>
        </PageHeader>

        <div class="mb-4 flex gap-1 border-b border-border" role="tablist" aria-label="Sources">
            <button
                v-for="source in [{ key: 'current', label: 'Current' }, { key: 'legacy', label: 'Legacy history' }]"
                :key="source.key"
                type="button"
                role="tab"
                :aria-selected="props.filters.source === source.key"
                class="-mb-px border-b-2 px-4 py-2 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                :class="props.filters.source === source.key ? 'border-primary text-primary' : 'border-transparent text-text-muted hover:text-text'"
                @click="switchSource(source.key)"
            >
                {{ source.label }}
            </button>
        </div>

        <div class="mb-4 grid gap-3 surface-raised p-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="relative">
                <label for="search" class="sr-only">Search</label>
                <Icon name="search" size="sm" class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-text-muted" />
                <input id="search" v-model="filters.search" type="search" placeholder="Search details or user" :class="[inputClass, 'pl-9']" />
            </div>
            <div>
                <label for="action" class="sr-only">Action</label>
                <Select id="action" v-model="filters.action" :options="actionOptions" />
            </div>
            <div class="flex items-center gap-2 lg:col-span-2">
                <label for="from" class="sr-only">From</label>
                <input id="from" v-model="filters.from" type="date" :class="inputClass" />
                <span class="text-text-muted" aria-hidden="true">–</span>
                <label for="to" class="sr-only">To</label>
                <input id="to" v-model="filters.to" type="date" :class="inputClass" />
            </div>
            <div v-if="anyFilter" class="sm:col-span-2 lg:col-span-4">
                <Button variant="ghost" size="sm" @click="clearFilters">
                    <Icon name="close" size="sm" />
                    Clear filters
                </Button>
            </div>
        </div>

        <Table :empty="entries.data.length === 0">
            <template #empty>
                <EmptyState icon="activity" title="Nothing recorded" description="No activity matches. Try another search or clear the filters." />
            </template>
            <thead>
                <tr>
                    <th scope="col">When</th>
                    <th scope="col">Who</th>
                    <th scope="col">Action</th>
                    <th scope="col">Details</th>
                    <th scope="col"><span class="sr-only">Record</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="entry in entries.data" :key="entry.id">
                    <td class="whitespace-nowrap text-sm">{{ formatWhen(entry.at) }}</td>
                    <td class="whitespace-nowrap text-sm">{{ entry.who ?? 'unknown' }}</td>
                    <td><Badge :tone="props.filters.source === 'legacy' ? 'neutral' : 'info'">{{ entry.action }}</Badge></td>
                    <td class="max-w-xl text-sm break-words">{{ entry.details }}</td>
                    <td class="text-right whitespace-nowrap">
                        <Button v-if="entry.record_id" variant="ghost" size="sm" :aria-label="`History of transaction ${entry.record_id}`" title="History" @click="viewing = { id: entry.record_id, name: `Transaction #${entry.record_id}`, type: '', status: null }">
                            #{{ entry.record_id }}
                        </Button>
                    </td>
                </tr>
            </tbody>
        </Table>

        <Pagination :prev-url="entries.prev_page_url" :next-url="entries.next_page_url" :current-page="entries.current_page" :last-page="entries.last_page" />

        <TimelineModal :record="viewing" @close="viewing = null" />
    </StaffLayout>
</template>
