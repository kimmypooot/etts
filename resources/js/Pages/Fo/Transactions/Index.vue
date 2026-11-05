<script setup>
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
import TextInput from '@/Components/Forms/TextInput.vue';
import FormGroup from '@/Components/Forms/FormGroup.vue';
import TransactionStatusBadge from '@/Components/Transactions/TransactionStatusBadge.vue';
import TimelineModal from '@/Components/Transactions/TimelineModal.vue';
import { formatDate } from '@/Utils/format.js';
import { useRowFlash } from '@/Composables/useRowFlash';
import { useLiveRefresh } from '@/Composables/useLiveRefresh';

const props = defineProps({
    transactions: { type: Object, required: true },
    filters: { type: Object, required: true },
    counts: { type: Object, required: true },
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

const typeOptions = [{ value: '', label: 'All types' }, ...props.options.types];
const statusOptions = computed(() => [{ value: '', label: 'All statuses' }, ...props.options.statuses]);

// ─── Forward one application ──────────────────────────────────────────────
const today = () => new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Manila' });
const forwarding = ref(null);
const forwardForm = useForm({ date: today() });

const openForward = (row) => {
    forwarding.value = row;
    forwardForm.defaults({ date: today() });
    forwardForm.reset();
    forwardForm.clearErrors();
};

const { flash, isFlashing } = useRowFlash();
useLiveRefresh(`${base}/pulse`, ['transactions', 'counts']);

const submitForward = () =>
    forwardForm.post(`${base}/${forwarding.value.id}/forward`, {
        preserveScroll: true,
        onSuccess: () => {
            flash(forwarding.value.id);
            forwarding.value = null;
        },
    });

// ─── History and delete ───────────────────────────────────────────────────
const viewing = ref(null);
const deleting = ref(null);
const deleteForm = useForm({ ids: [], reason: '', note: '' });
const deleteReasons = [{ value: '', label: 'Choose a reason…' }, ...props.options.deletionReasons];

const openDelete = (row) => {
    deleting.value = row;
    deleteForm.reset();
    deleteForm.clearErrors();
};

const submitDelete = () =>
    deleteForm.transform((data) => ({ ...data, ids: [deleting.value.id] })).post(`${base}/delete`, { preserveScroll: true, onSuccess: () => (deleting.value = null) });

const shortType = (type) => ({ 'Special Eligibility Application': 'SPELS', 'Certification and Authentication': 'Cert. + Auth.' })[type] ?? type;

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

        <div class="mb-4 grid gap-3 surface-raised p-4 sm:grid-cols-2 lg:grid-cols-5">
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
            <div class="flex items-center gap-2">
                <label for="from" class="sr-only">From</label>
                <input id="from" v-model="filters.from" type="date" :class="inputClass" />
                <span class="text-text-muted" aria-hidden="true">–</span>
                <label for="to" class="sr-only">To</label>
                <input id="to" v-model="filters.to" type="date" :class="inputClass" />
            </div>
            <div v-if="anyFilter" class="sm:col-span-2 lg:col-span-5">
                <Button variant="ghost" size="sm" @click="clearFilters"><Icon name="close" size="sm" />Clear filters</Button>
            </div>
        </div>

        <Table :empty="transactions.data.length === 0">
            <template #empty>
                <EmptyState icon="clipboard" title="No transactions" description="Nothing matches. Try another search or clear the filters." />
            </template>
            <thead>
                <tr>
                    <th scope="col">ID</th>
                    <th scope="col" :aria-sort="ariaSort('name')"><button type="button" class="font-semibold hover:underline" @click="sortBy('name')">Applicant</button></th>
                    <th scope="col">Type</th>
                    <th scope="col" :aria-sort="ariaSort('date')"><button type="button" class="font-semibold hover:underline" @click="sortBy('date')">Filed</button></th>
                    <th scope="col" :aria-sort="ariaSort('status')"><button type="button" class="font-semibold hover:underline" @click="sortBy('status')">Status</button></th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in transactions.data" :key="row.id" :class="{ 'row-missing-or': !row.official_receipt, 'row-just-updated': isFlashing(row.id) }">
                    <td class="whitespace-nowrap text-text-muted">#{{ row.id }}</td>
                    <td class="min-w-44 font-medium">
                        {{ row.name }}
                        <div v-if="row.eligibility" class="text-xs font-normal text-text-muted">{{ row.eligibility }}</div>
                    </td>
                    <td class="text-sm whitespace-nowrap" :title="row.type">{{ shortType(row.type) }}</td>
                    <td class="whitespace-nowrap text-sm">{{ formatDate(row.transaction_date) }}</td>
                    <td><TransactionStatusBadge :status="row.status" /></td>
                    <td class="text-right whitespace-nowrap">
                        <div class="flex max-w-[19rem] flex-wrap items-center justify-end gap-1">
                            <Button v-if="row.can_forward" variant="secondary" size="sm" @click="openForward(row)">Forward to RO</Button>
                            <Button v-if="row.can_work" variant="ghost" size="sm" :href="`${base}/${row.id}/edit`" :aria-label="`Edit transaction ${row.id}`">
                                <Icon name="pencil" size="sm" />
                                Edit
                            </Button>
                            <Button variant="ghost" size="sm" :aria-label="`History of transaction ${row.id}`" @click="viewing = row">
                                <Icon name="clock" size="sm" />
                                History
                            </Button>
                            <Button v-if="row.can_work" variant="ghost" size="sm" :aria-label="`Delete transaction ${row.id}`" @click="openDelete(row)">
                                <Icon name="trash" size="sm" />
                            </Button>
                        </div>
                    </td>
                </tr>
            </tbody>
        </Table>

        <p class="mt-3 text-sm text-text-muted">{{ transactions.total.toLocaleString('en-PH') }} transactions</p>
        <Pagination :prev-url="transactions.prev_page_url" :next-url="transactions.next_page_url" :current-page="transactions.current_page" :last-page="transactions.last_page" />

        <Modal :open="forwarding !== null" title="Forward to the Regional Office" tone="primary" icon="upload" @close="forwarding = null">
            <form v-if="forwarding" id="forward-form" class="space-y-4" @submit.prevent="submitForward">
                <p class="text-sm text-text-muted">#{{ forwarding.id }} · {{ forwarding.name }}</p>
                <TextInput id="forward-date" v-model="forwardForm.date" type="date" label="Date sent" required :invalid="!!forwardForm.errors.date" />
                <p v-if="forwardForm.errors.date" class="text-sm text-danger">{{ forwardForm.errors.date }}</p>
                <p class="text-xs text-text-muted">To send several at once with a routing slip, use a transmittal instead.</p>
            </form>
            <template #footer>
                <Button variant="secondary" @click="forwarding = null">Cancel</Button>
                <Button type="submit" form="forward-form" :loading="forwardForm.processing">Forward</Button>
            </template>
        </Modal>

        <TimelineModal :record="viewing" :base="base" @close="viewing = null" />

        <Modal :open="deleting !== null" :title="deleting ? `Delete #${deleting.id}` : ''" tone="danger" icon="trash" @close="deleting = null">
            <form v-if="deleting" id="delete-form" class="space-y-4" @submit.prevent="submitDelete">
                <Alert tone="warning">
                    {{ deleting.name }} is moved to your office's deleted records with your name, the time and this reason. Once the Regional Office has acted on a record, only ESD can delete it.
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
