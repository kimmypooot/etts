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
import Checkbox from '@/Components/Forms/Checkbox.vue';
import { confirm } from '@/Composables/useDialog';

const props = defineProps({
    centers: { type: Object, required: true },
    filters: { type: Object, required: true },
});

const filters = reactive({
    search: props.filters.search ?? '',
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
                router.get('/admin/exam-centers', query, { preserveState: true, preserveScroll: true, replace: true });
            },
            value.search !== old.search ? 300 : 0,
        );
    },
);

const toggleSort = () => (filters.dir = filters.dir === 'asc' ? 'desc' : 'asc');

// Add or edit, in one modal.
const editing = ref(null);
const formOpen = ref(false);
const form = useForm({ name: '', country: 'Philippines', is_active: true });

const openForm = (center = null) => {
    editing.value = center;
    form.defaults({
        name: center?.name ?? '',
        country: center?.country ?? 'Philippines',
        is_active: center?.is_active ?? true,
    });
    form.reset();
    form.clearErrors();
    formOpen.value = true;
};

const saveForm = () => {
    const options = { preserveScroll: true, onSuccess: () => (formOpen.value = false) };

    editing.value ? form.put(`/admin/exam-centers/${editing.value.id}`, options) : form.post('/admin/exam-centers', options);
};

const remove = async (center) => {
    const confirmed = await confirm({
        title: `Delete ${center.name}?`,
        text: 'This cannot be undone.',
        confirmText: 'Delete',
        tone: 'danger',
    });

    if (confirmed) {
        router.delete(`/admin/exam-centers/${center.id}`, { preserveScroll: true });
    }
};

const inputClass =
    'block w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-sm text-text shadow-xs focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring';
</script>

<template>
    <Head title="Places of Examination" />

    <StaffLayout>
        <PageHeader
            title="Places of Examination"
            description="The places of examination or issuance offered on the certification and authentication forms. A place that is in use cannot be deleted or renamed; switch it off instead."
            :breadcrumbs="[{ label: 'Dashboard', href: '/dashboard' }, { label: 'Places of Examination' }]"
        >
            <template #actions>
                <Button @click="openForm()">
                    <Icon name="plus" size="sm" />
                    Add place
                </Button>
            </template>
        </PageHeader>

        <div class="mb-4 rounded-lg border border-border bg-surface p-4">
            <label for="search" class="sr-only">Search places</label>
            <div class="relative max-w-md">
                <Icon name="search" size="sm" class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-text-muted" />
                <input id="search" v-model="filters.search" type="search" placeholder="Search by place or country" :class="[inputClass, 'pl-9']" />
            </div>
        </div>

        <Table :empty="centers.data.length === 0">
            <template #empty>
                <EmptyState icon="tag" title="No places match" description="Try another name or country." />
            </template>
            <thead>
                <tr>
                    <th scope="col" :aria-sort="filters.dir === 'asc' ? 'ascending' : 'descending'">
                        <button type="button" class="font-semibold hover:underline" @click="toggleSort">Place</button>
                    </th>
                    <th scope="col">Country</th>
                    <th scope="col">Status</th>
                    <th scope="col">Requests</th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="center in centers.data" :key="center.id">
                    <td class="min-w-48 font-medium">{{ center.name }}</td>
                    <td class="whitespace-nowrap">{{ center.country }}</td>
                    <td class="whitespace-nowrap">
                        <Badge :tone="center.is_active ? 'success' : 'neutral'">{{ center.is_active ? 'Active' : 'Off' }}</Badge>
                    </td>
                    <td>
                        <Badge :tone="center.usage_count > 0 ? 'info' : 'neutral'">{{ center.usage_count }}</Badge>
                    </td>
                    <td class="whitespace-nowrap text-right">
                        <div class="flex items-center justify-end gap-1">
                            <Button variant="ghost" size="sm" @click="openForm(center)">
                                <Icon name="pencil" size="sm" />
                                Edit
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                :disabled="center.usage_count > 0"
                                :title="center.usage_count > 0 ? 'In use by requests' : undefined"
                                @click="remove(center)"
                            >
                                <Icon name="trash" size="sm" />
                                Delete
                            </Button>
                        </div>
                    </td>
                </tr>
            </tbody>
        </Table>

        <p class="mt-3 text-sm text-text-muted">{{ centers.total }} places</p>
        <Pagination :prev-url="centers.prev_page_url" :next-url="centers.next_page_url" :current-page="centers.current_page" :last-page="centers.last_page" />

        <Modal :open="formOpen" :title="editing ? 'Edit place' : 'Add place'" :tone="editing ? 'primary' : 'success'" icon="tag" @close="formOpen = false">
            <form id="exam-center-form" class="space-y-4" @submit.prevent="saveForm">
                <TextInput id="center-name" v-model="form.name" label="Place" required :invalid="!!form.errors.name" />
                <InputError :message="form.errors.name" />
                <TextInput id="center-country" v-model="form.country" label="Country" required :invalid="!!form.errors.country" />
                <InputError :message="form.errors.country" />
                <Checkbox id="center-active" v-model="form.is_active" label="Offer this place on the forms" />
            </form>
            <template #footer>
                <Button variant="secondary" @click="formOpen = false">Cancel</Button>
                <Button type="submit" form="exam-center-form" :loading="form.processing">{{ editing ? 'Save changes' : 'Add place' }}</Button>
            </template>
        </Modal>
    </StaffLayout>
</template>
