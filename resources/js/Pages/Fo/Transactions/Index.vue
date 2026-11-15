<script setup>
import { personName } from '@/Utils/text.js';
import { computed, reactive, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import StaffLayout from '@/Layouts/StaffLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/UI/Button.vue';
import Table from '@/Components/UI/Table.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Icon from '@/Components/UI/Icon.vue';
import Modal from '@/Components/Feedback/Modal.vue';
import Alert from '@/Components/Feedback/Alert.vue';
import Select from '@/Components/Forms/Select.vue';
import FilterBar from '@/Components/Forms/FilterBar.vue';
import FilterField from '@/Components/Forms/FilterField.vue';
import SearchInput from '@/Components/Forms/SearchInput.vue';
import DateRange from '@/Components/Forms/DateRange.vue';
import FormGroup from '@/Components/Forms/FormGroup.vue';
import TransactionStatusBadge from '@/Components/Transactions/TransactionStatusBadge.vue';
import TransactionTypeBadge from '@/Components/Transactions/TransactionTypeBadge.vue';
import StatusChips from '@/Components/Transactions/StatusChips.vue';
import TimelineModal from '@/Components/Transactions/TimelineModal.vue';
import RowActions from '@/Components/Transactions/RowActions.vue';
import ActionDialog from '@/Components/Transactions/ActionDialog.vue';
import { formatDate } from '@/Utils/format.js';
import { useRowFlash } from '@/Composables/useRowFlash';
import { useLiveRefresh } from '@/Composables/useLiveRefresh';

const props = defineProps({
    transactions: { type: Object, required: true },
    filters: { type: Object, required: true },
    counts: { type: Object, required: true },
    statusCounts: { type: Object, required: true },
    options: { type: Object, required: true },
});

const base = '/fo/transactions';

const filters = reactive({
    search: props.filters.search ?? '',
    type: props.filters.type ?? '',
    status: props.filters.status ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

const anyFilter = computed(() => Object.values(filters).some((value) => value !== ''));

const visit = (extra = {}) => {
    const query = { board: props.filters.board, ...filters, sort: props.filters.sort, dir: props.filters.dir, ...extra };
    router.get(base, Object.fromEntries(Object.entries(query).filter(([, v]) => v !== '' && v != null)), { preserveState: true, preserveScroll: true, replace: true });
};

let timer;
watch(
    () => ({ ...filters }),
    (value, old) => {
        clearTimeout(timer);
        timer = setTimeout(() => visit(), value.search !== old.search ? 300 : 0);
    },
);

const clearFilters = () => Object.keys(filters).forEach((key) => (filters[key] = ''));
const switchBoard = (board) => router.get(base, { board }, { preserveScroll: true });
const sortBy = (column) => visit({ sort: column, dir: props.filters.sort === column && props.filters.dir === 'asc' ? 'desc' : 'asc' });
const ariaSort = (column) => (props.filters.sort === column ? (props.filters.dir === 'asc' ? 'ascending' : 'descending') : 'none');

const footerSummary = computed(() => {
    const page = props.transactions;

    return page.total === 0
        ? '0 transactions'
        : `Showing ${page.from.toLocaleString('en-PH')}–${page.to.toLocaleString('en-PH')} of ${page.total.toLocaleString('en-PH')} transactions`;
});

const typeOptions = [{ value: '', label: 'All types' }, ...props.options.types];

const { flash, isFlashing } = useRowFlash();
useLiveRefresh(`${base}/pulse`, ['transactions', 'counts']);

// ─── One record: the row's actions (TransactionActions on the server) ────
const acting = ref(null); // { row, action }
const openAction = (row, action) => (acting.value = { row, action });
const actionDone = (id) => {
    flash(id);
    acting.value = null;
};

// ─── History and delete ───────────────────────────────────────────────────
const viewing = ref(null);
const deleting = ref(null);
const deleteForm = useForm({ ids: [], reason: '', note: '' });
const deleteReasons = [{ value: '', label: 'Choose a reason…' }, ...props.options.deletionReasons];

const openDelete = (row) => {
    viewing.value = null;
    deleting.value = row;
    deleteForm.reset();
    deleteForm.clearErrors();
};

const submitDelete = () =>
    deleteForm.transform((data) => ({ ...data, ids: [deleting.value.id] })).post(`${base}/delete`, { preserveScroll: true, onSuccess: () => (deleting.value = null) });


const inputClass =
    'block w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-sm text-text shadow-xs focus:border-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring';
</script>

<template>
    <Head title="Transactions" />

    <StaffLayout>
        <PageHeader
            title="Transactions"
            description="The applications and requests filed at your office."
            :breadcrumbs="[{ label: 'Dashboard', href: '/fo/dashboard' }, { label: 'Transactions' }]"
        >
            <template #actions>
                <Button variant="outline" href="/fo/transactions/create?type=Certification">
                    <Icon name="plus" size="sm" />
                    New certification / authentication
                </Button>
                <Button href="/fo/transactions/create?type=Special%20Eligibility%20Application">
                    <Icon name="plus" size="sm" />
                    New application
                </Button>
            </template>
        </PageHeader>

        <div class="mb-4 flex gap-1 border-b border-border" role="tablist" aria-label="Boards">
            <button
                v-for="board in [{ key: 'active', label: 'Active' }, { key: 'history', label: 'History' }]"
                :key="board.key"
                type="button"
                role="tab"
                :aria-selected="props.filters.board === board.key"
                class="-mb-px border-b-2 px-4 py-2 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                :class="props.filters.board === board.key ? 'border-primary text-primary' : 'border-transparent text-text-muted hover:text-text'"
                @click="switchBoard(board.key)"
            >
                {{ board.label }}
                <span class="ml-1 rounded-full bg-surface-muted px-2 py-0.5 text-xs">{{ counts[board.key].toLocaleString('en-PH') }}</span>
            </button>
        </div>

        <FilterBar :active="anyFilter" @clear="clearFilters">
            <template #chips>
                <StatusChips v-model="filters.status" :counts="statusCounts" />
            </template>
            <FilterField label="Search" for-id="search" grow>
                <SearchInput id="search" v-model="filters.search" placeholder="Name, OR number, ID or eligibility" />
            </FilterField>
            <FilterField label="Type" for-id="type">
                <Select id="type" v-model="filters.type" :options="typeOptions" />
            </FilterField>
            <FilterField label="Filed between" for-id="from" wide>
                <DateRange v-model:from="filters.from" v-model:to="filters.to" />
            </FilterField>
        </FilterBar>

        <Table :empty="transactions.data.length === 0">
            <template #empty>
                <EmptyState icon="clipboard" title="No transactions" description="Nothing matches. Try another search or clear the filters." />
            </template>
            <thead>
                <tr>
                    <th scope="col" :aria-sort="ariaSort('name')"><button type="button" class="font-semibold hover:underline" @click="sortBy('name')">Applicant</button></th>
                    <th scope="col">Type</th>
                    <th scope="col" :aria-sort="ariaSort('date')"><button type="button" class="font-semibold hover:underline" @click="sortBy('date')">Filed</button></th>
                    <th scope="col" :aria-sort="ariaSort('status')"><button type="button" class="font-semibold hover:underline" @click="sortBy('status')">Status</button></th>
                    <th scope="col" class="sticky right-0 bg-inherit text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in transactions.data" :key="row.id" :class="{ 'row-missing-or': !row.official_receipt, 'row-just-updated': isFlashing(row.id) }">
                    <td class="min-w-48 font-medium">
                        {{ personName(row.name) }}
                        <div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs font-normal text-text-muted">
                            <span class="tabular-nums">#{{ row.id }}</span>
                            <span v-if="row.eligibility">· {{ row.eligibility }}</span>
                            <span v-if="!row.official_receipt" class="rounded bg-danger-bg px-1.5 py-0.5 font-semibold text-danger">No OR</span>
                        </div>
                    </td>
                    <td><TransactionTypeBadge :type="row.type" /></td>
                    <td class="whitespace-nowrap text-sm">{{ formatDate(row.transaction_date) }}</td>
                    <td><TransactionStatusBadge :status="row.status" /></td>
                    <td class="sticky right-0 min-w-56 bg-surface">
                        <RowActions :row="row" @act="openAction" @history="viewing = $event" />
                    </td>
                </tr>
            </tbody>
        </Table>

        <Pagination
            :summary="footerSummary"
            :prev-url="transactions.prev_page_url"
            :next-url="transactions.next_page_url"
            :current-page="transactions.current_page"
            :last-page="transactions.last_page"
        />

        <!-- Forward, cancel or return, from the row -->
        <ActionDialog :acting="acting" @close="acting = null" @done="actionDone" />

        <!-- Logs/History, with delete while the record is still with the office -->
        <TimelineModal :record="viewing" :base="base" @close="viewing = null">
            <template #tools>
                <div v-if="viewing?.can_work" class="mb-4 flex justify-end rounded-md border border-border bg-surface-muted p-3">
                    <Button variant="danger" size="sm" @click="openDelete(viewing)">
                        <Icon name="trash" size="sm" />
                        Delete
                    </Button>
                </div>
            </template>
        </TimelineModal>

        <Modal :open="deleting !== null" :title="deleting ? `Delete #${deleting.id}` : ''" tone="danger" icon="trash" @close="deleting = null">
            <form v-if="deleting" id="delete-form" class="space-y-4" @submit.prevent="submitDelete">
                <Alert tone="warning">
                    {{ personName(deleting.name) }} is moved to your office's deleted records with your name, the time and this reason. Once the Regional Office has acted on a record, only ESD can delete it.
                </Alert>
                <FormGroup id="delete-reason" label="Reason" required :error="deleteForm.errors.reason">
                    <Select id="delete-reason" v-model="deleteForm.reason" :options="deleteReasons" required />
                </FormGroup>
                <FormGroup id="delete-note" :label="`Explain (${options.noteMin} to ${options.noteMax} characters)`" required :error="deleteForm.errors.note">
                    <textarea id="delete-note" v-model="deleteForm.note" rows="3" :minlength="options.noteMin" :maxlength="options.noteMax" required :class="inputClass" />
                </FormGroup>
            </form>
            <template #footer>
                <Button variant="secondary" @click="deleting = null">Cancel</Button>
                <Button type="submit" form="delete-form" variant="danger" :loading="deleteForm.processing">Delete</Button>
            </template>
        </Modal>
    </StaffLayout>
</template>
