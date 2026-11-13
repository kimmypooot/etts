<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import Alert from '@/Components/Feedback/Alert.vue';
import Modal from '@/Components/Feedback/Modal.vue';
import SignatoryPicker from '@/Components/Transactions/SignatoryPicker.vue';

const props = defineProps({
    id: { type: Number, required: true },
    base: { type: String, required: true },
    type: { type: String, required: true },
    status: { type: String, required: true },
    finish: { type: String, default: null },
    finishStatus: { type: String, default: null },
    vcasUrl: { type: String, default: null },
    hasOriginal: { type: Boolean, default: false },
});

const isAuth = computed(() => props.type.includes('Authentication'));
const signatory = ref({ name: '', position: '', office: 'Examination Services Division' });

const openPrint = (path, query = '') => window.open(`${props.base}/${props.id}/${path}${query}`, '_blank', 'noopener');
const printErrf = () => openPrint('errf');
const printAuth = () =>
    openPrint('authenticated-copy', '?' + new URLSearchParams({ signatory: signatory.value.name, position: signatory.value.position, office: signatory.value.office }));

const sending = ref(false);
const sendToVcas = () => {
    sending.value = true;
    router.post(`${props.base}/${props.id}/process/vcas`, {}, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => (window.location.href = props.vcasUrl),
        onFinish: () => (sending.value = false),
    });
};

// Mark completed / Release, confirmed first.
const confirming = ref(false);
const moving = ref(false);
const finishLabel = computed(() => (props.finish === 'complete' ? 'Mark completed' : 'Release'));
const move = () => {
    // Read before posting: the props already describe the next state when onSuccess runs.
    const releasing = props.finish === 'release';
    moving.value = true;
    router.post(`${props.base}/${props.id}/status`, { status: props.finishStatus }, {
        preserveScroll: true,
        onSuccess: () => {
            confirming.value = false;
            if (releasing) router.visit(props.base);
        },
        onFinish: () => (moving.value = false),
    });
};

const cards = computed(() => {
    const list = [{ key: 'errf', title: 'Print request form', text: 'The Eligibility/Exam Records Request Form (ERRF) with the applicant, eligibility and verification data.' }];
    if (props.vcasUrl) list.push({ key: 'vcas', title: 'Process COE', text: 'Sends the saved record to VCAS on this workstation to prepare the certificate.' });
    if (isAuth.value) list.push({ key: 'auth', title: 'Print authenticated copy', text: 'Prints the original document with the "Certified Authenticated Copy" stamp, the O.R. number and a QR code.' });
    return list;
});
</script>

<template>
    <div class="space-y-6">
        <ol class="grid gap-4 lg:grid-cols-2">
            <li v-for="(card, index) in cards" :key="card.key" class="rounded-lg border border-border bg-surface p-4" :class="{ 'lg:col-span-2': card.key === 'auth' }">
                <div class="mb-1 flex items-center gap-2">
                    <span class="flex size-6 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary">{{ index + 1 }}</span>
                    <h3 class="text-sm font-semibold text-text">{{ card.title }}</h3>
                </div>
                <p class="mb-3 text-sm text-text-muted">{{ card.text }}</p>

                <Button v-if="card.key === 'errf'" type="button" variant="secondary" @click="printErrf"><Icon name="printer" size="sm" />Print request form</Button>

                <Button v-else-if="card.key === 'vcas'" type="button" variant="secondary" :loading="sending" @click="sendToVcas"><Icon name="desktop" size="sm" />Send to VCAS</Button>

                <template v-else-if="card.key === 'auth'">
                    <p v-if="!hasOriginal" class="text-sm text-text-muted">Upload the original document in step 4 first.</p>
                    <template v-else>
                        <SignatoryPicker v-model="signatory" class="mb-3" />
                        <Button type="button" variant="secondary" :disabled="!signatory.name.trim()" @click="printAuth"><Icon name="printer" size="sm" />Print authenticated copy</Button>
                    </template>
                </template>
            </li>
        </ol>

        <section class="rounded-lg border border-border bg-surface p-4" aria-labelledby="finish-heading">
            <h3 id="finish-heading" class="mb-1 text-sm font-semibold text-text">Finish</h3>
            <p v-if="status === 'submitted'" class="text-sm text-text-muted">Save the OR number in step 4 to receive this record before it can be completed.</p>
            <template v-else-if="finish">
                <p class="mb-3 text-sm text-text-muted">
                    {{ finish === 'complete' ? 'Once the documents are prepared, mark the record completed. Its details are then locked.' : 'Release the documents to the applicant. This closes the record.' }}
                </p>
                <Button type="button" :variant="finish === 'complete' ? 'primary' : 'success'" @click="confirming = true">
                    <Icon :name="finish === 'complete' ? 'checkCircle' : 'check'" size="sm" />{{ finishLabel }}
                </Button>
            </template>
            <p v-else class="text-sm text-text-muted">Nothing left to do on this record.</p>
        </section>

        <Modal :open="confirming" :title="finishLabel" tone="primary" icon="checkCircle" @close="confirming = false">
            <p class="text-sm text-text">
                {{ finish === 'complete' ? 'Mark this record completed? Its details will be locked; corrections then need a revert.' : 'Release this record? It will be closed.' }}
            </p>
            <template #footer>
                <Button variant="secondary" @click="confirming = false">Go back</Button>
                <Button :loading="moving" @click="move">{{ finishLabel }}</Button>
            </template>
        </Modal>
    </div>
</template>
