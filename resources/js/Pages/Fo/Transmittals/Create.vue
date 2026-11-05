<script setup>
import { personName } from '@/Utils/text.js';
import { computed, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import StaffLayout from '@/Layouts/StaffLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/UI/Button.vue';
import Table from '@/Components/UI/Table.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Icon from '@/Components/UI/Icon.vue';
import Alert from '@/Components/Feedback/Alert.vue';
import Field from '@/Components/Forms/Field.vue';
import Checkbox from '@/Components/Forms/Checkbox.vue';
import FormGroup from '@/Components/Forms/FormGroup.vue';
import TransactionStatusBadge from '@/Components/Transactions/TransactionStatusBadge.vue';
import { formatDate } from '@/Utils/format.js';

const props = defineProps({
    search: { type: String, default: '' },
    records: { type: Object, required: true },
});

const q = ref(props.search);
let timer;
watch(q, (value) => {
    clearTimeout(timer);
    timer = setTimeout(() => router.get('/fo/transmittals/create', { search: value || undefined }, { preserveState: true, replace: true }), 300);
});

const form = useForm({ ids: [], manual: [], remarks: '' });

const rows = computed(() => props.records.data);
const allSelected = computed(() => rows.value.length > 0 && rows.value.every((row) => form.ids.includes(row.id)));
const toggleAll = (checked) => (form.ids = checked ? rows.value.map((row) => row.id) : []);

const addDocument = () => form.manual.push({ manual_name: '', manual_description: '', manual_doc_type: '', remarks: '' });
const removeDocument = (index) => form.manual.splice(index, 1);

const total = computed(() => form.ids.length + form.manual.length);
const submit = () => form.post('/fo/transmittals');

const errorFor = (index, field) => form.errors[`manual.${index}.${field}`];

const inputClass =
    'block w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-sm text-text shadow-xs focus:border-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring';
</script>

<template>
    <Head title="New transmittal" />

    <StaffLayout>
        <PageHeader
            title="New transmittal to the Regional Office"
            description="Bundle your new and corrected special eligibility applications, with any supporting documents, for the Regional Office."
            :breadcrumbs="[{ label: 'Dashboard', href: '/fo/dashboard' }, { label: 'Transmittals', href: '/fo/transmittals' }, { label: 'New' }]"
        />

        <form class="max-w-5xl" @submit.prevent="submit">
            <Alert v-if="form.errors.ids" tone="danger" class="mb-4">{{ form.errors.ids }}</Alert>

            <h2 class="mb-3 text-base font-semibold text-text">Applications to send</h2>

            <div class="mb-3 max-w-md">
                <label for="search" class="sr-only">Search</label>
                <input id="search" v-model="q" type="search" placeholder="Name, ID or eligibility" :class="inputClass" />
            </div>

            <Table :empty="rows.length === 0">
                <template #empty>
                    <EmptyState icon="envelope" title="No applications ready" description="New and returned applications that are not already in another draft appear here." />
                </template>
                <thead>
                    <tr>
                        <th scope="col" class="w-10">
                            <Checkbox id="all" :model-value="allSelected" label="" @update:model-value="toggleAll" />
                            <span class="sr-only">Select all</span>
                        </th>
                        <th scope="col">ID</th>
                        <th scope="col">Applicant</th>
                        <th scope="col">Filed</th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.id">
                        <td>
                            <input v-model="form.ids" type="checkbox" :value="row.id" :aria-label="`Select application ${row.id}`" class="size-4 accent-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring" />
                        </td>
                        <td class="text-text-muted">#{{ row.id }}</td>
                        <td class="font-medium">
                            {{ personName(row.name) }}
                            <div v-if="row.eligibility" class="text-xs font-normal text-text-muted">{{ row.eligibility }}</div>
                        </td>
                        <td class="text-sm whitespace-nowrap">{{ formatDate(row.date) }}</td>
                        <td><TransactionStatusBadge :status="row.status" /></td>
                    </tr>
                </tbody>
            </Table>
            <p v-if="records.total > rows.length" class="mt-2 text-sm text-text-muted">Showing the latest {{ rows.length }} of {{ records.total }}. Search to narrow the list.</p>

            <div class="mt-8 mb-3 flex items-center justify-between">
                <h2 class="text-base font-semibold text-text">Other documents</h2>
                <Button type="button" size="sm" variant="outline" @click="addDocument"><Icon name="plus" size="sm" />Add a document</Button>
            </div>
            <p v-if="form.manual.length === 0" class="text-sm text-text-muted">Letters, certificates or anything else that travels with the applications.</p>
            <div v-for="(doc, index) in form.manual" :key="index" class="mb-3 grid gap-3 rounded-lg border border-border bg-surface p-4 sm:grid-cols-2">
                <Field :id="`m-name-${index}`" v-model="doc.manual_name" label="Name" required :error="errorFor(index, 'manual_name')" />
                <Field :id="`m-type-${index}`" v-model="doc.manual_doc_type" label="Document type" :error="errorFor(index, 'manual_doc_type')" />
                <Field :id="`m-desc-${index}`" v-model="doc.manual_description" label="Description" required :error="errorFor(index, 'manual_description')" class="sm:col-span-2" />
                <Field :id="`m-rem-${index}`" v-model="doc.remarks" label="Remarks" :error="errorFor(index, 'remarks')" />
                <div class="flex items-end justify-end">
                    <Button type="button" variant="ghost" size="sm" @click="removeDocument(index)"><Icon name="trash" size="sm" />Remove</Button>
                </div>
            </div>

            <FormGroup id="remarks" label="Remarks (optional)" class="mt-6" :error="form.errors.remarks">
                <input id="remarks" v-model="form.remarks" type="text" maxlength="200" :class="inputClass" />
            </FormGroup>

            <div class="mt-6 flex items-center justify-end gap-3">
                <span class="text-sm text-text-muted">{{ total }} item(s)</span>
                <Button variant="secondary" href="/fo/transmittals">Cancel</Button>
                <Button type="submit" :disabled="total === 0" :loading="form.processing">Save draft</Button>
            </div>
        </form>
    </StaffLayout>
</template>
