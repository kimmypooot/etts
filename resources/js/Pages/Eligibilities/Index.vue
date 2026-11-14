<script setup>
import { reactive, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import StaffLayout from '@/Layouts/StaffLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/UI/Button.vue';
import Table from '@/Components/UI/Table.vue';
import Badge from '@/Components/UI/Badge.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Icon from '@/Components/UI/Icon.vue';
import Modal from '@/Components/Feedback/Modal.vue';
import TextInput from '@/Components/Forms/TextInput.vue';
import InputError from '@/Components/Forms/InputError.vue';
import FormGroup from '@/Components/Forms/FormGroup.vue';
import Select from '@/Components/Forms/Select.vue';
import { confirm } from '@/Composables/useDialog';

const props = defineProps({
    eligibilities: { type: Object, required: true },
    filters: { type: Object, required: true },
    options: { type: Object, required: true },
});

// The classification is optional: "Not set" sends a blank, which clears it.
const notSet = { value: '', label: 'Not set' };
const categoryOptions = [notSet, ...props.options.acquisitionCategories];
const levelOptions = [notSet, ...props.options.positionLevels];

const filters = reactive({
    search: props.filters.search ?? '',
    sort: props.filters.sort ?? 'code',
    dir: props.filters.dir ?? 'asc',
});

let searchTimer;
watch(
    () => ({ ...filters }),
    (value, old) => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(
            () => {
                const query = Object.fromEntries(Object.entries(value).filter(([, v]) => v !== ''));
                router.get('/admin/eligibilities', query, { preserveState: true, preserveScroll: true, replace: true });
            },
            value.search !== old.search ? 300 : 0,
        );
    },
);

const sortBy = (column) => {
    if (filters.sort === column) {
        filters.dir = filters.dir === 'asc' ? 'desc' : 'asc';
    } else {
        filters.sort = column;
        filters.dir = 'asc';
    }
};

const ariaSort = (column) => (filters.sort === column ? (filters.dir === 'asc' ? 'ascending' : 'descending') : 'none');

// Add or edit, in one modal.
const editing = ref(null);
const formOpen = ref(false);
const form = useForm({ code: '', name: '', acquisition_category: '', position_level_equivalent: '', legal_basis: '' });

const openForm = (eligibility = null) => {
    editing.value = eligibility;
    form.defaults({
        code: eligibility?.code ?? '',
        name: eligibility?.name ?? '',
        acquisition_category: eligibility?.acquisition_category ?? '',
        position_level_equivalent: eligibility?.position_level_equivalent ?? '',
        legal_basis: eligibility?.legal_basis ?? '',
    });
    form.reset();
    form.clearErrors();
    formOpen.value = true;
};

const saveForm = () => {
    const options = { preserveScroll: true, onSuccess: () => (formOpen.value = false) };

    editing.value ? form.put(`/admin/eligibilities/${editing.value.id}`, options) : form.post('/admin/eligibilities', options);
};

const remove = async (eligibility) => {
    const confirmed = await confirm({
        title: `Delete ${eligibility.code}?`,
        text: 'This cannot be undone.',
        confirmText: 'Delete',
        tone: 'danger',
    });

    if (confirmed) {
        router.delete(`/admin/eligibilities/${eligibility.id}`, { preserveScroll: true });
    }
};

const inputClass =
    'block w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-sm text-text shadow-xs focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring';
</script>

<template>
    <Head title="Eligibilities" />

    <StaffLayout>
        <PageHeader
            title="Eligibilities"
            description="The eligibilities a transaction can be filed under. Special eligibility applications offer only those obtained by special grant. A code that is in use cannot be deleted or renamed."
            :breadcrumbs="[{ label: 'Dashboard', href: '/dashboard' }, { label: 'Eligibilities' }]"
        >
            <template #actions>
                <Button @click="openForm()">
                    <Icon name="plus" size="sm" />
                    Add eligibility
                </Button>
            </template>
        </PageHeader>

        <div class="mb-4 rounded-lg border border-border bg-surface p-4">
            <label for="search" class="sr-only">Search eligibilities</label>
            <div class="relative max-w-md">
                <Icon name="search" size="sm" class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-text-muted" />
                <input id="search" v-model="filters.search" type="search" placeholder="Search by code or title" :class="[inputClass, 'pl-9']" />
            </div>
        </div>

        <Table :empty="eligibilities.data.length === 0">
            <template #empty>
                <EmptyState icon="tag" title="No eligibilities match" description="Try another code or title." />
            </template>
            <thead>
                <tr>
                    <th scope="col" :aria-sort="ariaSort('code')">
                        <button type="button" class="font-semibold hover:underline" @click="sortBy('code')">Code</button>
                    </th>
                    <th scope="col" :aria-sort="ariaSort('name')">
                        <button type="button" class="font-semibold hover:underline" @click="sortBy('name')">Title</button>
                    </th>
                    <th scope="col">Acquisition</th>
                    <th scope="col">Position level</th>
                    <th scope="col">Legal basis</th>
                    <th scope="col">Records</th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="eligibility in eligibilities.data" :key="eligibility.id">
                    <td class="font-medium whitespace-nowrap">{{ eligibility.code }}</td>
                    <td class="min-w-48">{{ eligibility.name }}</td>
                    <td class="whitespace-nowrap">
                        <Badge v-if="eligibility.acquisition_category_label" tone="neutral">{{ eligibility.acquisition_category_label }}</Badge>
                        <span v-else class="text-text-muted">Not set</span>
                    </td>
                    <td class="whitespace-nowrap">
                        <Badge v-if="eligibility.position_level_equivalent_label" tone="neutral">{{ eligibility.position_level_equivalent_label }}</Badge>
                        <span v-else class="text-text-muted">Not set</span>
                    </td>
                    <td class="min-w-40">
                        <template v-if="eligibility.legal_basis">{{ eligibility.legal_basis }}</template>
                        <span v-else class="text-text-muted">Not set</span>
                    </td>
                    <td>
                        <Badge :tone="eligibility.usage_count > 0 ? 'info' : 'neutral'">{{ eligibility.usage_count }}</Badge>
                    </td>
                    <td class="whitespace-nowrap text-right">
                        <div class="flex items-center justify-end gap-1">
                            <Button variant="ghost" size="sm" @click="openForm(eligibility)">
                                <Icon name="pencil" size="sm" />
                                Edit
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                :disabled="eligibility.usage_count > 0"
                                :title="eligibility.usage_count > 0 ? 'In use by records' : undefined"
                                @click="remove(eligibility)"
                            >
                                <Icon name="trash" size="sm" />
                                Delete
                            </Button>
                        </div>
                    </td>
                </tr>
            </tbody>
        </Table>

        <p class="mt-3 text-sm text-text-muted">{{ eligibilities.total }} eligibilities</p>
        <Pagination
            :prev-url="eligibilities.prev_page_url"
            :next-url="eligibilities.next_page_url"
            :current-page="eligibilities.current_page"
            :last-page="eligibilities.last_page"
        />

        <Modal :open="formOpen" :title="editing ? 'Edit eligibility' : 'Add eligibility'" :tone="editing ? 'primary' : 'success'" icon="tag" @close="formOpen = false">
            <form id="eligibility-form" class="space-y-4" @submit.prevent="saveForm">
                <TextInput id="eligibility-code" v-model="form.code" label="Code" required :invalid="!!form.errors.code" />
                <InputError :message="form.errors.code" />
                <TextInput id="eligibility-name" v-model="form.name" label="Title" required :invalid="!!form.errors.name" />
                <InputError :message="form.errors.name" />
                <FormGroup id="eligibility-category" label="Acquisition category" :error="form.errors.acquisition_category">
                    <Select id="eligibility-category" v-model="form.acquisition_category" :options="categoryOptions" :invalid="!!form.errors.acquisition_category" />
                </FormGroup>
                <FormGroup id="eligibility-level" label="Position level equivalent" :error="form.errors.position_level_equivalent">
                    <Select id="eligibility-level" v-model="form.position_level_equivalent" :options="levelOptions" :invalid="!!form.errors.position_level_equivalent" />
                </FormGroup>
                <TextInput id="eligibility-basis" v-model="form.legal_basis" label="Legal basis" :invalid="!!form.errors.legal_basis" />
                <InputError :message="form.errors.legal_basis" />
            </form>
            <template #footer>
                <Button variant="secondary" @click="formOpen = false">Cancel</Button>
                <Button type="submit" form="eligibility-form" :loading="form.processing">{{ editing ? 'Save changes' : 'Add eligibility' }}</Button>
            </template>
        </Modal>
    </StaffLayout>
</template>
