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
import Checkbox from '@/Components/Forms/Checkbox.vue';
import TextInput from '@/Components/Forms/TextInput.vue';
import FormGroup from '@/Components/Forms/FormGroup.vue';
import TransactionStatusBadge from '@/Components/Transactions/TransactionStatusBadge.vue';
import StatusChips from '@/Components/Transactions/StatusChips.vue';
import TimelineModal from '@/Components/Transactions/TimelineModal.vue';
import { useAuthorization } from '@/Composables/useAuthorization';
import { confirm } from '@/Composables/useDialog';
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

const { can } = useAuthorization();
const { flash, isFlashing } = useRowFlash();
useLiveRefresh('/admin/transactions/pulse', ['transactions', 'counts']);

// ─── Filters (all applied in SQL) ─────────────────────────────────────────
const filters = reactive({
    search: props.filters.search ?? '',
    type: props.filters.type ?? '',
    status: props.filters.status ?? '',
    office: props.filters.office ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

const anyFilter = computed(() => Object.values(filters).some((value) => value !== ''));

const visit = (extra = {}) => {
    const query = { board: props.filters.board, ...filters, sort: props.filters.sort, dir: props.filters.dir, ...extra };
    router.get('/admin/transactions', Object.fromEntries(Object.entries(query).filter(([, v]) => v !== '' && v != null)), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

let searchTimer;
watch(
    () => ({ ...filters }),
    (value, old) => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => visit(), value.search !== old.search ? 300 : 0);
    },
);

const clearFilters = () => Object.keys(filters).forEach((key) => (filters[key] = ''));

const switchBoard = (board) => {
    selected.value = [];
    router.get('/admin/transactions', { board }, { preserveScroll: true });
};

const sortBy = (column) =>
    visit({ sort: column, dir: props.filters.sort === column && props.filters.dir === 'asc' ? 'desc' : 'asc' });
const ariaSort = (column) => (props.filters.sort === column ? (props.filters.dir === 'asc' ? 'ascending' : 'descending') : 'none');

const typeOptions = [{ value: '', label: 'All types' }, ...props.options.types];
const statusOptions = computed(() => [{ value: '', label: 'All statuses' }, ...props.options.statuses]);
const officeOptions = [{ value: '', label: 'All offices' }, ...props.options.offices];

// ─── Selection and batch ──────────────────────────────────────────────────
const selected = ref([]);
const pageIds = computed(() => props.transactions.data.map((row) => row.id));
const allSelected = computed(() => pageIds.value.length > 0 && pageIds.value.every((id) => selected.value.includes(id)));
const toggleAll = (checked) => (selected.value = checked ? [...pageIds.value] : []);

watch(() => props.transactions.data, () => (selected.value = selected.value.filter((id) => pageIds.value.includes(id))));

const batchTarget = ref('');
const batchChoices = computed(() => [
    { value: '', label: 'Apply a status…' },
    ...props.options.batchTargets.map((status) => ({ value: status, label: status.charAt(0).toUpperCase() + status.slice(1) })),
]);

const applyBatch = async () => {
    const confirmed = await confirm({
        title: `Set ${selected.value.length} transaction(s) to ${batchTarget.value}?`,
        text: 'They must all have the same status, and each must be allowed to make this move. If any is not, nothing changes.',
        confirmText: 'Apply',
    });

    if (confirmed) {
        router.post('/admin/transactions/batch-status', { ids: selected.value, status: batchTarget.value }, {
            preserveScroll: true,
            onSuccess: () => {
                flash(selected.value);
                selected.value = [];
                batchTarget.value = '';
            },
        });
    }
};

// ─── One record: forward / receive / status ───────────────────────────────
const today = () => new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Manila' });

const acting = ref(null); // { row, action }
const actionForm = useForm({ date: today(), remarks: '', status: '' });

const openAction = (row, action) => {
    acting.value = { row, action };
    actionForm.defaults({ date: today(), remarks: '', status: action.status ?? '' });
    actionForm.reset();
    actionForm.clearErrors();
};

const submitAction = () => {
    const { row, action } = acting.value;
    const url = { forward: 'forward', receive: 'receive', status: 'status' }[action.kind];
    const payload = action.kind === 'status' ? { status: action.status, date: actionForm.date, remarks: actionForm.remarks } : { date: actionForm.date };

    actionForm.transform(() => payload).post(`/admin/transactions/${row.id}/${url}`, {
        preserveScroll: true,
        onSuccess: () => {
            flash(row.id);
            acting.value = null;
        },
    });
};

// ─── One record: history, revert, delete ──────────────────────────────────
const viewing = ref(null);

const revertTo = ref('');
const revertChoices = computed(() => [
    { value: '', label: 'Revert to…' },
    ...props.options.revertTargets.filter((status) => status !== viewing.value?.status).map((status) => ({ value: status, label: status.charAt(0).toUpperCase() + status.slice(1) })),
]);

const revert = async () => {
    const confirmed = await confirm({
        title: `Revert #${viewing.value.id} to ${revertTo.value}?`,
        text: 'Use this only to correct a mistake. The previous status is kept in the history.',
        confirmText: 'Revert',
        tone: 'danger',
    });

    if (confirmed) {
        router.post(`/admin/transactions/${viewing.value.id}/revert`, { status: revertTo.value }, {
            preserveScroll: true,
            onSuccess: () => {
                flash(viewing.value.id);
                viewing.value = null;
                revertTo.value = '';
            },
        });
    }
};

const deleting = ref([]); // ids
const deleteForm = useForm({ ids: [], reason: '', note: '' });
const deleteReasons = [{ value: '', label: 'Choose a reason…' }, ...props.options.deletionReasons];

const openDelete = (ids) => {
    deleting.value = ids;
    deleteForm.reset();
    deleteForm.clearErrors();
    viewing.value = null;
};

const submitDelete = () => {
    deleteForm.transform((data) => ({ ...data, ids: deleting.value })).post('/admin/transactions/delete', {
        preserveScroll: true,
        onSuccess: () => {
            deleting.value = [];
            selected.value = [];
        },
    });
};

// ─── Export and quick edit ────────────────────────────────────────────────
const exportUrl = computed(() => {
    const query = Object.fromEntries(
        Object.entries({ board: props.filters.board, ...filters, sort: props.filters.sort, dir: props.filters.dir }).filter(([, v]) => v !== '' && v != null),
    );

    return `/admin/transactions/export?${new URLSearchParams(query)}`;
});

const quick = ref(null);
const quickForm = useForm({ official_receipt: '', transaction_details: '', remarks: '', date_sent_to_ro: '', date_received_ro: '', date_completed: '' });

const openQuick = (row) => {
    quick.value = row;
    viewing.value = null;
    quickForm.defaults({
        official_receipt: row.official_receipt ?? '',
        transaction_details: row.transaction_details ?? '',
        remarks: row.remarks ?? '',
        date_sent_to_ro: row.date_sent_to_ro ?? '',
        date_received_ro: row.date_received_ro ?? '',
        date_completed: row.date_completed ?? '',
    });
    quickForm.reset();
    quickForm.clearErrors();
};

const submitQuick = () =>
    quickForm.patch(`/admin/transactions/${quick.value.id}/quick`, { preserveScroll: true, onSuccess: () => (quick.value = null) });

const shortType = (type) => ({ 'Special Eligibility Application': 'SPELS', 'Certification and Authentication': 'Cert. + Auth.' })[type] ?? type;

const inputClass =
    'block w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-sm text-text shadow-xs focus:border-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring';
</script>

<template>
    <Head title="Transactions" />

    <StaffLayout>
        <PageHeader
            title="Transactions"
            description="Certifications, authentications and Special Eligibility Applications filed at ESD and the field offices."
            :breadcrumbs="[{ label: 'Dashboard', href: '/dashboard' }, { label: 'Transactions' }]"
        >
            <template #actions>
                <Button v-if="can('transactions.export')" variant="outline" :href="exportUrl" external>
                    <Icon name="download" size="sm" />
                    Export CSV
                </Button>
                <Button v-if="can('transactions.manage')" href="/admin/transactions/create">
                    <Icon name="plus" size="sm" />
                    New transaction
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

        <StatusChips v-model="filters.status" :counts="statusCounts" />

        <div class="mb-4 grid gap-3 surface-raised p-4 sm:grid-cols-2 lg:grid-cols-7">
            <div class="relative sm:col-span-2">
                <label for="search" class="sr-only">Search</label>
                <Icon name="search" size="sm" class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-text-muted" />
                <input id="search" v-model="filters.search" type="search" placeholder="Name, OR number, ID or eligibility" :class="[inputClass, 'pl-9']" />
            </div>
            <div>
                <label for="type" class="sr-only">Type</label>
                <Select id="type" v-model="filters.type" :options="typeOptions" />
            </div>
            <div>
                <label for="status" class="sr-only">Status</label>
                <Select id="status" v-model="filters.status" :options="statusOptions" />
            </div>
            <div>
                <label for="office" class="sr-only">Office</label>
                <Select id="office" v-model="filters.office" :options="officeOptions" />
            </div>
            <div class="flex min-w-0 items-center gap-2 sm:col-span-2">
                <label for="from" class="sr-only">From</label>
                <input id="from" v-model="filters.from" type="date" :class="[inputClass, 'min-w-0']" />
                <span class="text-text-muted" aria-hidden="true">–</span>
                <label for="to" class="sr-only">To</label>
                <input id="to" v-model="filters.to" type="date" :class="[inputClass, 'min-w-0']" />
            </div>
            <div v-if="anyFilter" class="sm:col-span-2 lg:col-span-6">
                <Button variant="ghost" size="sm" @click="clearFilters">
                    <Icon name="close" size="sm" />
                    Clear filters
                </Button>
            </div>
        </div>

        <div
            v-if="selected.length"
            class="mb-4 flex flex-wrap items-center gap-3 rounded-lg border border-primary/30 bg-surface p-3 text-sm"
            role="region"
            aria-label="Selected transactions"
        >
            <span class="font-medium">{{ selected.length }} selected</span>
            <div class="w-48">
                <label for="batch" class="sr-only">Status to apply</label>
                <Select id="batch" v-model="batchTarget" :options="batchChoices" />
            </div>
            <Button v-if="can('transactions.process')" size="sm" :disabled="!batchTarget" @click="applyBatch">Apply</Button>
            <Button v-if="can('transactions.delete')" variant="danger" size="sm" @click="openDelete(selected)">
                <Icon name="trash" size="sm" />
                Delete
            </Button>
            <Button variant="ghost" size="sm" @click="selected = []">Clear selection</Button>
        </div>

        <Table :empty="transactions.data.length === 0">
            <template #empty>
                <EmptyState icon="clipboard" title="No transactions match" description="Try another search or clear the filters." />
            </template>
            <thead>
                <tr>
                    <th scope="col" class="w-10">
                        <Checkbox id="select-all" :model-value="allSelected" label="" @update:model-value="toggleAll" />
                        <span class="sr-only">Select all on this page</span>
                    </th>
                    <th scope="col">ID</th>
                    <th scope="col" :aria-sort="ariaSort('name')"><button type="button" class="font-semibold hover:underline" @click="sortBy('name')">Applicant</button></th>
                    <th scope="col">Type</th>
                    <th scope="col">Office</th>
                    <th scope="col" :aria-sort="ariaSort('date')"><button type="button" class="font-semibold hover:underline" @click="sortBy('date')">Filed</button></th>
                    <th scope="col" :aria-sort="ariaSort('status')"><button type="button" class="font-semibold hover:underline" @click="sortBy('status')">Status</button></th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in transactions.data" :key="row.id" :class="{ 'row-missing-or': !row.official_receipt, 'row-just-updated': isFlashing(row.id) }">
                    <td>
                        <input
                            v-model="selected"
                            type="checkbox"
                            :value="row.id"
                            :aria-label="`Select transaction ${row.id}`"
                            class="size-4 accent-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                        />
                    </td>
                    <td class="whitespace-nowrap text-text-muted">#{{ row.id }}</td>
                    <td class="min-w-44 font-medium">
                        {{ personName(row.name) }}
                        <div v-if="row.eligibility" class="text-xs font-normal text-text-muted">{{ row.eligibility }}</div>
                    </td>
                    <td class="text-sm whitespace-nowrap" :title="row.type">{{ shortType(row.type) }}</td>
                    <td class="whitespace-nowrap text-sm">{{ row.office }}</td>
                    <td class="whitespace-nowrap text-sm">{{ formatDate(row.transaction_date) }}</td>
                    <td><TransactionStatusBadge :status="row.status" /></td>
                    <td class="text-right whitespace-nowrap">
                        <div class="flex items-center justify-end gap-2">
                        <div class="flex max-w-[17rem] flex-wrap items-center justify-end gap-1">
                            <Button
                                v-for="action in can('transactions.process') ? row.actions : []"
                                :key="action.kind + action.status"
                                :variant="action.status === 'Cancelled' || action.status === 'Disapproved' ? 'outline' : 'secondary'"
                                size="sm"
                                @click="openAction(row, action)"
                            >
                                {{ action.label }}
                            </Button>
                        </div>
                        <div class="flex shrink-0 items-center">
                            <Button v-if="can('transactions.manage')" variant="ghost" size="sm" :href="`/admin/transactions/${row.id}/edit`" :aria-label="`Edit transaction ${row.id}`" title="Edit" icon-only>
                                <Icon name="pencil" size="sm" />
                            </Button>
                            <Button variant="ghost" size="sm" :aria-label="`History of transaction ${row.id}`" title="History" icon-only @click="viewing = row">
                                <Icon name="clock" size="sm" />
                            </Button>
                        </div>
                        </div>
                    </td>
                </tr>
            </tbody>
        </Table>

        <p class="mt-3 text-sm text-text-muted">{{ transactions.total.toLocaleString('en-PH') }} transactions</p>
        <Pagination
            :prev-url="transactions.prev_page_url"
            :next-url="transactions.next_page_url"
            :current-page="transactions.current_page"
            :last-page="transactions.last_page"
        />

        <!-- Forward / receive / change status -->
        <Modal :open="acting !== null" :title="acting?.action.label ?? ''" tone="primary" icon="check" @close="acting = null">
            <form v-if="acting" id="action-form" class="space-y-4" @submit.prevent="submitAction">
                <p class="text-sm text-text-muted">
                    #{{ acting.row.id }} · {{ personName(acting.row.name) }} · <TransactionStatusBadge :status="acting.row.status" />
                </p>
                <TextInput id="action-date" v-model="actionForm.date" type="date" label="Date" required :invalid="!!actionForm.errors.date" />
                <p v-if="actionForm.errors.date" class="text-sm text-danger">{{ actionForm.errors.date }}</p>
                <FormGroup v-if="acting.action.kind === 'status'" id="action-remarks" :label="acting.action.needs_remarks ? 'Reason / remarks' : 'Remarks (optional)'" :required="acting.action.needs_remarks" :error="actionForm.errors.remarks">
                    <textarea id="action-remarks" v-model="actionForm.remarks" rows="3" maxlength="1000" :required="acting.action.needs_remarks" :class="inputClass" />
                </FormGroup>
                <Alert v-if="acting.action.status === 'Approved' || acting.action.status === 'Approved/Released'" tone="info">
                    An applicant may hold only one approved record per eligibility. If one is already on file, this will be refused.
                </Alert>
            </form>
            <template #footer>
                <Button variant="secondary" @click="acting = null">Cancel</Button>
                <Button type="submit" form="action-form" :loading="actionForm.processing">{{ acting?.action.label }}</Button>
            </template>
        </Modal>

        <!-- History, with revert and delete for the record -->
        <TimelineModal :record="viewing" @close="viewing = null">
            <template #tools>
                <div v-if="viewing && (can('transactions.process') || can('transactions.manage') || can('transactions.delete'))" class="mb-4 flex flex-wrap items-center gap-2 rounded-md border border-border bg-surface-muted p-3">
                    <template v-if="can('transactions.process')">
                        <div class="w-44">
                            <label for="revert-to" class="sr-only">Revert to</label>
                            <Select id="revert-to" v-model="revertTo" :options="revertChoices" />
                        </div>
                        <Button variant="outline" size="sm" :disabled="!revertTo" @click="revert">Revert</Button>
                    </template>
                    <Button v-if="can('transactions.manage')" variant="outline" size="sm" @click="openQuick(viewing)">
                        <Icon name="pencil" size="sm" />
                        Quick edit
                    </Button>
                    <Button v-if="can('transactions.delete')" variant="danger" size="sm" class="ml-auto" @click="openDelete([viewing.id])">
                        <Icon name="trash" size="sm" />
                        Delete
                    </Button>
                </div>
            </template>
        </TimelineModal>

        <!-- Quick edit: the fields staff correct most -->
        <Modal :open="quick !== null" :title="quick ? `Quick edit #${quick.id}` : ''" tone="primary" icon="pencil" @close="quick = null">
            <form id="quick-form" class="space-y-4" @submit.prevent="submitQuick">
                <p v-if="quick" class="text-sm text-text-muted">{{ personName(quick.name) }}</p>
                <TextInput id="quick-or" v-model="quickForm.official_receipt" label="OR number" :invalid="!!quickForm.errors.official_receipt" />
                <p v-if="quickForm.errors.official_receipt" class="text-sm text-danger">{{ quickForm.errors.official_receipt }}</p>
                <div class="grid gap-3 sm:grid-cols-3">
                    <TextInput id="quick-sent" v-model="quickForm.date_sent_to_ro" type="date" label="Sent to RO" :invalid="!!quickForm.errors.date_sent_to_ro" />
                    <TextInput id="quick-received" v-model="quickForm.date_received_ro" type="date" label="Received by AO" :invalid="!!quickForm.errors.date_received_ro" />
                    <TextInput id="quick-completed" v-model="quickForm.date_completed" type="date" label="Completed" :invalid="!!quickForm.errors.date_completed" />
                </div>
                <FormGroup id="quick-details" label="Details" :error="quickForm.errors.transaction_details">
                    <textarea id="quick-details" v-model="quickForm.transaction_details" rows="2" maxlength="500" :class="inputClass" />
                </FormGroup>
                <FormGroup id="quick-remarks" label="Remarks" :error="quickForm.errors.remarks">
                    <textarea id="quick-remarks" v-model="quickForm.remarks" rows="3" maxlength="1000" :class="inputClass" />
                </FormGroup>
            </form>
            <template #footer>
                <Button variant="secondary" @click="quick = null">Cancel</Button>
                <Button type="submit" form="quick-form" :loading="quickForm.processing">Save</Button>
            </template>
        </Modal>

        <!-- Delete: reason and note are mandatory -->
        <Modal :open="deleting.length > 0" :title="`Delete ${deleting.length} transaction(s)`" tone="danger" icon="trash" @close="deleting = []">
            <form id="delete-form" class="space-y-4" @submit.prevent="submitDelete">
                <Alert tone="warning">
                    The transaction is moved to the deleted-records archive with your name, the time and this reason. It can be seen there, not edited.
                </Alert>
                <FormGroup id="delete-reason" label="Reason" required :error="deleteForm.errors.reason">
                    <Select id="delete-reason" v-model="deleteForm.reason" :options="deleteReasons" required />
                </FormGroup>
                <FormGroup id="delete-note" :label="`Explain (${options.noteMin} to ${options.noteMax} characters)`" required :error="deleteForm.errors.note">
                    <textarea id="delete-note" v-model="deleteForm.note" rows="3" :minlength="options.noteMin" :maxlength="options.noteMax" required :class="inputClass" />
                </FormGroup>
                <p v-if="deleteForm.errors.ids" class="text-sm text-danger">{{ deleteForm.errors.ids }}</p>
            </form>
            <template #footer>
                <Button variant="secondary" @click="deleting = []">Cancel</Button>
                <Button type="submit" form="delete-form" variant="danger" :loading="deleteForm.processing">Delete</Button>
            </template>
        </Modal>
    </StaffLayout>
</template>
