<script setup>
import { personName } from '@/Utils/text.js';
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import StaffLayout from '@/Layouts/StaffLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/UI/Button.vue';
import Table from '@/Components/UI/Table.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Icon from '@/Components/UI/Icon.vue';
import Alert from '@/Components/Feedback/Alert.vue';
import FormGroup from '@/Components/Forms/FormGroup.vue';
import Checkbox from '@/Components/Forms/Checkbox.vue';
import TransactionStatusBadge from '@/Components/Transactions/TransactionStatusBadge.vue';
import { formatDate } from '@/Utils/format.js';

const props = defineProps({
    offices: { type: Array, required: true },
    office: { type: Number, default: null },
    search: { type: String, default: '' },
    records: { type: Object, default: null },
});

const selectedOffice = computed(() => props.offices.find((office) => office.id === props.office) ?? null);

const q = ref(props.search);
let timer;
watch(q, (value) => {
    clearTimeout(timer);
    timer = setTimeout(() => router.get('/admin/transmittals/create', { office: props.office, search: value || undefined }, { preserveState: true, replace: true }), 300);
});

const form = useForm({ ids: [], remarks: '' });

const rows = computed(() => props.records?.data ?? []);
const allSelected = computed(() => rows.value.length > 0 && rows.value.every((row) => form.ids.includes(row.id)));
const toggleAll = (checked) => (form.ids = checked ? rows.value.map((row) => row.id) : []);

const submit = () => form.post('/admin/transmittals');

const inputClass =
    'block w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-sm text-text shadow-xs focus:border-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring';
</script>

<template>
    <Head title="New transmittal" />

    <StaffLayout>
        <PageHeader
            title="New transmittal"
            description="Bundle finished documents for one field office. Only completed, approved, disapproved and returned transactions that are not already in an open draft are offered."
            :breadcrumbs="[{ label: 'Dashboard', href: '/dashboard' }, { label: 'Transmittals', href: '/admin/transmittals' }, { label: 'New' }]"
        />

        <section v-if="!office" aria-labelledby="pick-office" class="max-w-3xl">
            <h2 id="pick-office" class="mb-3 text-base font-semibold text-text">1. Choose the field office</h2>
            <EmptyState v-if="offices.length === 0" icon="envelope" title="Nothing is waiting" description="No completed, approved, disapproved or returned transactions are free to bundle right now." />
            <ul v-else class="grid gap-3 sm:grid-cols-2">
                <li v-for="item in offices" :key="item.id">
                    <Link
                        :href="`/admin/transmittals/create?office=${item.id}`"
                        class="flex items-center justify-between rounded-lg border border-border bg-surface p-4 hover:border-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                    >
                        <span>
                            <span class="block font-medium text-text">{{ item.code }}</span>
                            <span class="block text-xs text-text-muted">{{ item.name }}</span>
                        </span>
                        <span class="rounded-full bg-surface-muted px-2.5 py-1 text-sm font-semibold">{{ item.waiting }} waiting</span>
                    </Link>
                </li>
            </ul>
        </section>

        <form v-else class="max-w-5xl" @submit.prevent="submit">
            <div class="mb-4 flex items-center justify-between gap-3">
                <h2 class="text-base font-semibold text-text">2. Choose the transactions for {{ selectedOffice?.code ?? 'this office' }}</h2>
                <Button variant="ghost" size="sm" href="/admin/transmittals/create">Change office</Button>
            </div>

            <Alert v-if="form.errors.ids" tone="danger" class="mb-4">{{ form.errors.ids }}</Alert>

            <div class="mb-3 max-w-md">
                <label for="search" class="sr-only">Search</label>
                <input id="search" v-model="q" type="search" placeholder="Name, ID or eligibility" :class="inputClass" />
            </div>

            <Table :empty="rows.length === 0">
                <template #empty>
                    <EmptyState icon="envelope" title="No transactions" description="Nothing matches for this office." />
                </template>
                <thead>
                    <tr>
                        <th scope="col" class="w-10">
                            <Checkbox id="all" :model-value="allSelected" label="" @update:model-value="toggleAll" />
                            <span class="sr-only">Select all</span>
                        </th>
                        <th scope="col">ID</th>
                        <th scope="col">Applicant</th>
                        <th scope="col">Type</th>
                        <th scope="col">Filed</th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.id">
                        <td>
                            <input v-model="form.ids" type="checkbox" :value="row.id" :aria-label="`Select transaction ${row.id}`" class="size-4 accent-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring" />
                        </td>
                        <td class="text-text-muted">#{{ row.id }}</td>
                        <td class="font-medium">
                            {{ personName(row.name) }}
                            <div v-if="row.eligibility" class="text-xs font-normal text-text-muted">{{ row.eligibility }}</div>
                        </td>
                        <td class="text-sm">{{ row.type }}</td>
                        <td class="text-sm whitespace-nowrap">{{ formatDate(row.date) }}</td>
                        <td><TransactionStatusBadge :status="row.status" /></td>
                    </tr>
                </tbody>
            </Table>
            <p v-if="records && records.total > rows.length" class="mt-2 text-sm text-text-muted">Showing the latest {{ rows.length }} of {{ records.total }}. Search to narrow the list.</p>

            <FormGroup id="remarks" label="Remarks (optional)" class="mt-6" :error="form.errors.remarks">
                <input id="remarks" v-model="form.remarks" type="text" maxlength="200" :class="inputClass" />
            </FormGroup>

            <div class="mt-6 flex items-center justify-end gap-3">
                <span class="text-sm text-text-muted">{{ form.ids.length }} selected</span>
                <Button variant="secondary" href="/admin/transmittals">Cancel</Button>
                <Button type="submit" :disabled="form.ids.length === 0" :loading="form.processing">Create draft</Button>
            </div>
        </form>
    </StaffLayout>
</template>
