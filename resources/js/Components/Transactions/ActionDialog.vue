<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Button from '@/Components/UI/Button.vue';
import Modal from '@/Components/Feedback/Modal.vue';
import Alert from '@/Components/Feedback/Alert.vue';
import TextInput from '@/Components/Forms/TextInput.vue';
import FormGroup from '@/Components/Forms/FormGroup.vue';
import TransactionStatusBadge from '@/Components/Transactions/TransactionStatusBadge.vue';
import { personName } from '@/Utils/text.js';

/**
 * Confirms one row action from the board (forward, a status change, revert)
 * and posts it to the URL the server gave. What each action needs (a date, a
 * reason) comes with the action itself.
 */
const props = defineProps({
    // { row, action } or null when closed.
    acting: { type: Object, default: null },
});

const emit = defineEmits(['close', 'done']);

const today = () => new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Manila' });
const form = useForm({ date: today(), remarks: '', status: '' });

watch(
    () => props.acting,
    (acting) => {
        if (!acting) return;
        form.defaults({ date: today(), remarks: '', status: acting.action.status ?? '' });
        form.reset();
        form.clearErrors();
    },
);

const action = computed(() => props.acting?.action ?? null);
const row = computed(() => props.acting?.row ?? null);
const danger = computed(() => ['Cancelled', 'Returned', 'Disapproved'].includes(action.value?.status) || action.value?.kind === 'revert');

const title = computed(() => {
    if (!action.value) return '';
    if (action.value.kind === 'revert') return `Revert #${row.value.id} to ${action.value.status}?`;
    if (action.value.kind === 'forward') return 'Forward to the Regional Office';

    return `${action.value.label} #${row.value.id}`;
});

const submit = () => {
    const kind = action.value.kind;
    const payload = kind === 'revert'
        ? { status: action.value.status }
        : kind === 'forward'
          ? { date: form.date }
          : { status: action.value.status, ...(action.value.needs_date ? { date: form.date } : {}), remarks: form.remarks };

    const id = row.value.id;
    form.transform(() => payload).post(action.value.url, {
        preserveScroll: true,
        onSuccess: (page) => {
            if (page.props.flash?.error) return;
            emit('done', id);
        },
    });
};

const inputClass =
    'block w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-sm text-text shadow-xs focus:border-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring';
</script>

<template>
    <Modal :open="acting !== null" :title="title" :tone="danger ? 'danger' : 'primary'" :icon="danger ? 'exclamation' : 'check'" @close="emit('close')">
        <form v-if="acting" id="row-action-form" class="space-y-4" @submit.prevent="submit">
            <p class="text-sm text-text-muted">#{{ row.id }} · {{ personName(row.name) }} · <TransactionStatusBadge :status="row.status" /></p>

            <Alert v-if="action.kind === 'revert'" tone="warning">Use this only to correct a mistake. The record goes back one step, to {{ action.status }}; the change is kept in its history.</Alert>

            <template v-else>
                <template v-if="action.needs_date">
                    <TextInput id="row-action-date" v-model="form.date" type="date" :label="action.kind === 'forward' ? 'Date sent' : 'Date'" required :invalid="!!form.errors.date" />
                    <p v-if="form.errors.date" class="text-sm text-danger">{{ form.errors.date }}</p>
                </template>
                <FormGroup v-if="action.kind === 'status'" id="row-action-remarks" :label="action.needs_remarks ? 'Reason' : 'Remarks (optional)'" :required="action.needs_remarks" :error="form.errors.remarks">
                    <textarea id="row-action-remarks" v-model="form.remarks" rows="3" maxlength="1000" :required="action.needs_remarks" :class="inputClass" />
                </FormGroup>
                <Alert v-if="action.status === 'Cancelled'" tone="warning">The record stays on file as Cancelled.</Alert>
                <Alert v-if="action.status === 'Returned'" tone="warning">The application is closed as Returned. The record stays on file.</Alert>
                <Alert v-if="action.status === 'Approved'" tone="info">An applicant may hold only one approved record per eligibility. If one is already on file, this will be refused.</Alert>
                <p v-if="action.kind === 'forward'" class="text-xs text-text-muted">To send several at once with a routing slip, use a transmittal instead.</p>
            </template>
        </form>
        <template #footer>
            <Button variant="secondary" @click="emit('close')">Go back</Button>
            <Button type="submit" form="row-action-form" :variant="danger ? 'danger' : 'primary'" :loading="form.processing">{{ action?.kind === 'revert' ? 'Revert' : action?.label }}</Button>
        </template>
    </Modal>
</template>
