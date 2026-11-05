<script setup>
import { reactive, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import StaffLayout from '@/Layouts/StaffLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/UI/Button.vue';
import Table from '@/Components/UI/Table.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Icon from '@/Components/UI/Icon.vue';
import Modal from '@/Components/Feedback/Modal.vue';
import Alert from '@/Components/Feedback/Alert.vue';
import Field from '@/Components/Forms/Field.vue';
import TransmittalStatusBadge from '@/Components/Transmittals/TransmittalStatusBadge.vue';
import TransactionStatusBadge from '@/Components/Transactions/TransactionStatusBadge.vue';
import { confirm } from '@/Composables/useDialog';

const props = defineProps({
    transmittal: { type: Object, required: true },
    items: { type: Array, required: true },
    base: { type: String, default: '/admin/transmittals' },
    capabilities: { type: Object, required: true },
    portal: { type: String, default: 'esd' },
});

const editable = props.capabilities.edit;
const isDown = props.transmittal.direction === 'ESD_TO_FO';

const base = `${props.base}/${props.transmittal.id}`;
const reload = { preserveScroll: true };

// ─── Remarks ──────────────────────────────────────────────────────────────
const remarks = ref(props.transmittal.remarks ?? '');
const saveRemarks = () => remarks.value !== (props.transmittal.remarks ?? '') && router.patch(`${base}/remarks`, { remarks: remarks.value }, reload);

// ─── A transaction's note (details / remarks), edited in the draft ────────
const notes = reactive({});
const noteValue = (item, field) => notes[`${item.transaction_id}.${field}`] ?? (field === 'transaction_details' ? item.details : item.record_remarks) ?? '';
const saveNote = (item, field, value) => {
    const original = (field === 'transaction_details' ? item.details : item.record_remarks) ?? '';
    if (value !== original) router.patch(`${base}/records/${item.transaction_id}/note`, { field, value }, reload);
};

// ─── Add a transaction ────────────────────────────────────────────────────
const adding = ref(false);
const picker = reactive({ search: '', rows: [], total: 0, state: 'idle' });

const loadEligible = async () => {
    picker.state = 'loading';
    try {
        const response = await fetch(`${base}/eligible?search=${encodeURIComponent(picker.search)}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (!response.ok) throw new Error(String(response.status));
        const body = await response.json();
        picker.rows = body.data;
        picker.total = body.total;
        picker.state = 'idle';
    } catch {
        picker.state = 'error';
    }
};

const openAdd = () => {
    adding.value = true;
    picker.search = '';
    loadEligible();
};

let pickerTimer;
const onPickerSearch = () => {
    clearTimeout(pickerTimer);
    pickerTimer = setTimeout(loadEligible, 300);
};

const addRecord = (row) =>
    router.post(`${base}/records`, { transaction_id: row.id }, { ...reload, onSuccess: () => loadEligible() });

// ─── Manual documents ─────────────────────────────────────────────────────
const manualFor = ref(null); // null | 'new' | item
const manualForm = useForm({ manual_name: '', manual_description: '', manual_doc_type: '', remarks: '' });

const openManual = (item = null) => {
    manualFor.value = item ?? 'new';
    manualForm.defaults({
        manual_name: item?.name ?? '',
        manual_description: item?.description ?? '',
        manual_doc_type: item?.doc_type ?? '',
        remarks: item?.remarks ?? '',
    });
    manualForm.reset();
    manualForm.clearErrors();
};

const saveManual = () => {
    const options = { ...reload, onSuccess: () => (manualFor.value = null) };
    manualFor.value === 'new' ? manualForm.post(`${base}/manual`, options) : manualForm.put(`${base}/manual/${manualFor.value.id}`, options);
};

// ─── Row, release, delete ─────────────────────────────────────────────────
const removeItem = async (item) => {
    if (await confirm({ title: 'Remove this row?', text: item.name, confirmText: 'Remove', tone: 'danger' })) {
        router.delete(`${base}/items/${item.id}`, reload);
    }
};

const release = async () => {
    const confirmed = await confirm({
        title: `Release ${props.transmittal.number}?`,
        text: 'Each transaction gets its release date: approved applications become Approved/Released and completed certifications become Released. A disapproved or returned application keeps its status. The batch can no longer be edited.',
        confirmText: 'Release',
    });
    if (confirmed) router.post(`${base}/release`, {}, reload);
};

const transmit = async () => {
    const confirmed = await confirm({
        title: `Transmit ${props.transmittal.number}?`,
        text: 'The applications in this batch become forwarded to the Regional Office, dated today. The batch can no longer be edited.',
        confirmText: 'Transmit',
    });
    if (confirmed) router.post(`${base}/transmit`, {}, reload);
};

const destroy = async () => {
    if (await confirm({ title: `Delete ${props.transmittal.number}?`, text: 'The draft is removed. The transactions become available again.', confirmText: 'Delete', tone: 'danger' })) {
        router.delete(base);
    }
};

const formatWhen = (iso) =>
    iso ? new Date(iso).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' }) : '';

const inputClass =
    'block w-full rounded-md border border-border-strong bg-surface px-2 py-1.5 text-sm text-text shadow-xs focus:border-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring';
</script>

<template>
    <Head :title="transmittal.number" />

    <StaffLayout>
        <PageHeader
            :title="transmittal.number"
            :breadcrumbs="[{ label: 'Dashboard', href: '/dashboard' }, { label: 'Transmittals', href: props.base }, { label: transmittal.number }]"
        >
            <template #actions>
                <Button variant="outline" :href="`${base}/slip`">
                    <Icon name="printer" size="sm" />
                    Routing slip
                </Button>
                <Button v-if="capabilities.release" @click="release">Release</Button>
                <Button v-if="capabilities.transmit" @click="transmit">Transmit to the Regional Office</Button>
                <Button v-if="capabilities.delete" variant="danger" @click="destroy">
                    <Icon name="trash" size="sm" />
                    Delete draft
                </Button>
            </template>
        </PageHeader>

        <div class="mb-6 grid gap-4 rounded-lg border border-border bg-surface p-5 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <p class="text-xs font-semibold tracking-wide text-text-muted uppercase">{{ isDown ? 'To' : 'From' }}</p>
                <p class="font-medium">{{ transmittal.office?.name }}</p>
            </div>
            <div>
                <p class="text-xs font-semibold tracking-wide text-text-muted uppercase">Status</p>
                <TransmittalStatusBadge :status="transmittal.status" />
            </div>
            <div>
                <p class="text-xs font-semibold tracking-wide text-text-muted uppercase">Created</p>
                <p class="text-sm">{{ formatWhen(transmittal.created_at) }}<span class="block text-xs text-text-muted">{{ transmittal.created_by }}</span></p>
            </div>
            <div>
                <label for="remarks" class="text-xs font-semibold tracking-wide text-text-muted uppercase">Remarks</label>
                <input id="remarks" v-model="remarks" type="text" maxlength="200" :readonly="!editable" :class="inputClass" @blur="saveRemarks" @keydown.enter.prevent="saveRemarks" />
            </div>
        </div>

        <Alert v-if="!transmittal.draft" tone="info" class="mb-6">
            This transmittal has been {{ transmittal.status.toLowerCase() }} and can no longer be edited. You can still print its routing slip.
        </Alert>

        <div class="mb-3 flex items-center justify-between gap-2">
            <h2 class="text-base font-semibold text-text">Documents ({{ items.length }})</h2>
            <div v-if="editable" class="flex gap-2">
                <Button size="sm" @click="openAdd"><Icon name="plus" size="sm" />Add transaction</Button>
                <Button size="sm" variant="outline" @click="openManual()"><Icon name="plus" size="sm" />Add other document</Button>
            </div>
        </div>

        <Table :empty="items.length === 0">
            <template #empty>
                <EmptyState icon="envelope" title="No documents" description="Add a transaction or another document to this transmittal." />
            </template>
            <thead>
                <tr>
                    <th scope="col">Applicant / document</th>
                    <th scope="col">Type</th>
                    <th scope="col">Details</th>
                    <th scope="col">Remarks</th>
                    <th v-if="editable" scope="col"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="item in items" :key="item.id">
                    <template v-if="item.is_manual">
                        <td class="font-medium">{{ item.name }}<Badge class="ml-2">Other</Badge></td>
                        <td class="text-sm">{{ item.doc_type }}</td>
                        <td class="text-sm">{{ item.description }}</td>
                        <td class="text-sm">{{ item.remarks }}</td>
                    </template>
                    <template v-else>
                        <td class="font-medium">
                            #{{ item.transaction_id }} {{ item.name }}
                            <Badge v-if="item.deleted" tone="danger" class="ml-2">Deleted</Badge>
                            <div class="mt-1 flex items-center gap-2 text-xs font-normal text-text-muted">
                                <TransactionStatusBadge v-if="item.status" :status="item.status" />
                                <span v-if="item.eligibility">{{ item.eligibility }}</span>
                            </div>
                        </td>
                        <td class="text-sm">{{ item.type }}</td>
                        <td class="text-sm">
                            <input v-if="editable" :value="noteValue(item, 'transaction_details')" :aria-label="`Details of transaction ${item.transaction_id}`" maxlength="500" :class="inputClass" @input="notes[`${item.transaction_id}.transaction_details`] = $event.target.value" @blur="saveNote(item, 'transaction_details', $event.target.value)" />
                            <template v-else>{{ item.details }}</template>
                        </td>
                        <td class="text-sm">
                            <input v-if="editable" :value="noteValue(item, 'remarks')" :aria-label="`Remarks of transaction ${item.transaction_id}`" maxlength="1000" :class="inputClass" @input="notes[`${item.transaction_id}.remarks`] = $event.target.value" @blur="saveNote(item, 'remarks', $event.target.value)" />
                            <template v-else>{{ item.record_remarks }}</template>
                        </td>
                    </template>
                    <td v-if="editable" class="text-right whitespace-nowrap">
                        <Button v-if="item.is_manual" variant="ghost" size="sm" @click="openManual(item)"><Icon name="pencil" size="sm" />Edit</Button>
                        <Button variant="ghost" size="sm" :aria-label="`Remove ${item.name}`" @click="removeItem(item)"><Icon name="trash" size="sm" />Remove</Button>
                    </td>
                </tr>
            </tbody>
        </Table>

        <Modal :open="adding" title="Add a transaction" tone="primary" icon="plus" size="lg" @close="adding = false">
            <div class="mb-3">
                <label for="picker-search" class="sr-only">Search</label>
                <input id="picker-search" v-model="picker.search" type="search" placeholder="Name, ID or eligibility" :class="inputClass" @input="onPickerSearch" />
            </div>
            <p v-if="picker.state === 'loading'" class="py-6 text-center text-sm text-text-muted" role="status">Loading…</p>
            <Alert v-else-if="picker.state === 'error'" tone="danger">The list could not be loaded. Close this and try again.</Alert>
            <EmptyState v-else-if="picker.rows.length === 0" icon="envelope" title="Nothing to add" description="No other transaction of this office is free to add." />
            <ul v-else class="divide-y divide-border">
                <li v-for="row in picker.rows" :key="row.id" class="flex items-center justify-between gap-3 py-2 text-sm">
                    <span>
                        <span class="font-medium">#{{ row.id }} {{ row.name }}</span>
                        <span class="block text-xs text-text-muted">{{ row.type }}<template v-if="row.eligibility"> · {{ row.eligibility }}</template> · {{ row.status }}</span>
                    </span>
                    <Button size="sm" variant="secondary" @click="addRecord(row)">Add</Button>
                </li>
            </ul>
            <p v-if="picker.total > picker.rows.length" class="mt-2 text-xs text-text-muted">Showing {{ picker.rows.length }} of {{ picker.total }}. Search to narrow the list.</p>
        </Modal>

        <Modal :open="manualFor !== null" :title="manualFor === 'new' ? 'Add another document' : 'Edit document'" tone="primary" icon="documentText" @close="manualFor = null">
            <form id="manual-form" class="space-y-4" @submit.prevent="saveManual">
                <Field id="manual-name" v-model="manualForm.manual_name" label="Name" required :error="manualForm.errors.manual_name" />
                <Field id="manual-description" v-model="manualForm.manual_description" label="Description" required :error="manualForm.errors.manual_description" />
                <Field id="manual-type" v-model="manualForm.manual_doc_type" label="Document type" :error="manualForm.errors.manual_doc_type" />
                <Field id="manual-remarks" v-model="manualForm.remarks" label="Remarks" :error="manualForm.errors.remarks" />
            </form>
            <template #footer>
                <Button variant="secondary" @click="manualFor = null">Cancel</Button>
                <Button type="submit" form="manual-form" :loading="manualForm.processing">Save</Button>
            </template>
        </Modal>
    </StaffLayout>
</template>
