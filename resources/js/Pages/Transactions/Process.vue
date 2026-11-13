<script setup>
import { computed, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import StaffLayout from '@/Layouts/StaffLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import Alert from '@/Components/Feedback/Alert.vue';
import Modal from '@/Components/Feedback/Modal.vue';
import TransactionStatusBadge from '@/Components/Transactions/TransactionStatusBadge.vue';
import TransactionTypeBadge from '@/Components/Transactions/TransactionTypeBadge.vue';
import StepBar from '@/Components/Process/StepBar.vue';
import PersonalStep from '@/Components/Process/PersonalStep.vue';
import EligibilityStep from '@/Components/Process/EligibilityStep.vue';
import VerificationStep from '@/Components/Process/VerificationStep.vue';
import PaymentStep from '@/Components/Process/PaymentStep.vue';
import FinishStep from '@/Components/Process/FinishStep.vue';
import AttachmentsPanel from '@/Components/Process/AttachmentsPanel.vue';
import { personName } from '@/Utils/text.js';

const props = defineProps({
    id: { type: Number, required: true },
    status: { type: String, required: true },
    version: { type: String, default: null },
    record: { type: Object, required: true },
    attachments: { type: Array, default: () => [] },
    options: { type: Object, required: true },
    base: { type: String, default: '/admin/transactions' },
    office: { type: String, default: null },
    steps: { type: Array, required: true },
    step: { type: Number, required: true },
    locked: { type: Boolean, default: false },
    finish: { type: String, default: null },
    finishStatus: { type: String, default: null },
    vcasUrl: { type: String, default: null },
});

const today = () => new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Manila' });

// The saved record as form values: null becomes '' so inputs stay controlled.
const values = () => ({
    ...Object.fromEntries(Object.entries(props.record).map(([key, value]) => [key, value ?? ''])),
    or_date: props.record.or_date || today(),
    attachment_1: null,
    attachment_2: null,
    version: props.version,
});

const form = useForm(values());

// After a save the server sends the fresh record and version: start clean from them.
watch(
    () => props.version,
    () => {
        form.defaults(values());
        form.reset();
    },
);

const current = ref(props.step);
watch(
    () => props.step,
    (step) => (current.value = step),
);

// The furthest step that can be opened: the first one still incomplete.
const reachable = computed(() => props.steps.find((s) => s.number < 5 && s.missing.length > 0)?.number ?? 5);
const currentStep = computed(() => props.steps[current.value - 1]);

// Leaving a step with unsaved edits asks first.
const pending = ref(null);
const go = (step) => {
    if (step === current.value || step > reachable.value) return;
    if (form.isDirty && !props.locked) {
        pending.value = step;
        return;
    }
    current.value = step;
    form.clearErrors();
};
const discard = () => {
    form.reset();
    form.clearErrors();
    current.value = pending.value;
    pending.value = null;
};

const save = () => {
    form.post(`${props.base}/${props.id}/process/${current.value}`, {
        preserveScroll: true,
        forceFormData: current.value === 4,
    });
};

const name = computed(() => personName([props.record.lname, [props.record.fname, props.record.mname, props.record.extn].filter(Boolean).join(' ')].join(', ')));
const hasOriginal = computed(() => props.attachments.some((a) => a.slot === 2));
</script>

<template>
    <Head :title="`Process #${id}`" />

    <StaffLayout>
        <PageHeader
            :title="name"
            :breadcrumbs="[{ label: 'Dashboard', href: '/dashboard' }, { label: 'Transactions', href: base }, { label: `#${id}` }]"
        >
            <template #actions>
                <TransactionStatusBadge :status="status" />
            </template>
        </PageHeader>

        <div class="-mt-2 mb-6 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-text-muted">
            <TransactionTypeBadge :type="record.transaction_type" />
            <span class="tabular-nums">#{{ id }}</span>
            <span v-if="office" class="inline-flex items-center gap-1"><Icon name="building" size="sm" />{{ office }}</span>
            <span class="inline-flex items-center gap-1">
                <Icon name="documentText" size="sm" />
                <template v-if="record.official_receipt">OR {{ record.official_receipt }}</template>
                <span v-else class="rounded bg-danger-bg px-1.5 py-0.5 text-xs font-semibold text-danger">No OR</span>
            </span>
        </div>

        <Alert v-if="locked" tone="info" class="mb-6">
            This record is {{ status.toLowerCase() }}, so its details are locked. To correct them, revert it on the transactions board first.
        </Alert>

        <StepBar :steps="steps" :current="current" :reachable="reachable" @go="go" />

        <div class="gap-6 lg:grid lg:grid-cols-[minmax(0,1fr)_22rem]">
            <form class="mb-6 rounded-lg border border-border bg-surface p-5" :aria-labelledby="`step-${current}-heading`" @submit.prevent="save">
                <div class="mb-5">
                    <p class="text-xs font-semibold tracking-wide text-text-muted uppercase">Step {{ current }} of 5</p>
                    <h2 :id="`step-${current}-heading`" class="text-lg font-semibold text-text">{{ currentStep.label }}</h2>
                </div>

                <PersonalStep v-if="current === 1" :form="form" :options="options" :readonly="locked" />
                <EligibilityStep v-else-if="current === 2" :form="form" :readonly="locked" />
                <VerificationStep v-else-if="current === 3" :form="form" :readonly="locked" />
                <PaymentStep v-else-if="current === 4" :form="form" :options="options" :attachments="attachments" :readonly="locked" />
                <FinishStep
                    v-else
                    :id="id"
                    :base="base"
                    :type="record.transaction_type"
                    :status="status"
                    :finish="finish"
                    :finish-status="finishStatus"
                    :vcas-url="vcasUrl"
                    :has-original="hasOriginal"
                />

                <div class="mt-6 flex items-center justify-between gap-2 border-t border-border pt-4">
                    <Button v-if="current > 1" type="button" variant="secondary" @click="go(current - 1)"><Icon name="chevronLeft" size="sm" />Back</Button>
                    <Button v-else type="button" variant="secondary" :href="base">Back to transactions</Button>

                    <Button v-if="current < 5 && !locked" type="submit" :loading="form.processing">
                        Save and continue<Icon name="chevronRight" size="sm" />
                    </Button>
                    <Button v-else-if="current < 5" type="button" @click="go(current + 1)">Next<Icon name="chevronRight" size="sm" /></Button>
                </div>
            </form>

            <AttachmentsPanel :key="attachments.map((a) => a.url).join()" :attachments="attachments" />
        </div>

        <Modal :open="pending !== null" title="Discard changes?" tone="danger" icon="exclamation" @close="pending = null">
            <p class="text-sm text-text">You have unsaved changes on this step. Leave without saving them?</p>
            <template #footer>
                <Button variant="secondary" @click="pending = null">Keep editing</Button>
                <Button variant="danger" @click="discard">Discard changes</Button>
            </template>
        </Modal>
    </StaffLayout>
</template>
