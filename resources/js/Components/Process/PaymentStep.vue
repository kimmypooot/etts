<script setup>
import { computed } from 'vue';
import Field from '@/Components/Forms/Field.vue';
import FormGroup from '@/Components/Forms/FormGroup.vue';
import Select from '@/Components/Forms/Select.vue';
import FileInput from '@/Components/Forms/FileInput.vue';
import { formatNumber } from '@/Utils/format.js';

const props = defineProps({
    form: { type: Object, required: true },
    options: { type: Object, required: true },
    attachments: { type: Array, default: () => [] },
    readonly: { type: Boolean, default: false },
});

const typeOptions = computed(() => props.options.types.filter((type) => type.value !== 'Special Eligibility Application'));
const price = computed(() => (Number(props.form.coe_copies) || 0) * props.options.prices.coe + (Number(props.form.auth_copies) || 0) * props.options.prices.auth);
const slots = [
    { slot: 1, label: 'Valid ID' },
    { slot: 2, label: 'Original document' },
];
const onFile = (slot) => props.attachments.find((a) => a.slot === slot);
</script>

<template>
    <fieldset :disabled="readonly" class="space-y-6">
        <legend class="sr-only">Payment and files</legend>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <FormGroup id="transaction_type" label="Transaction type" required :error="form.errors.transaction_type">
                <Select id="transaction_type" v-model="form.transaction_type" :options="typeOptions" />
            </FormGroup>
            <Field id="coe_copies" v-model="form.coe_copies" type="number" label="Certificate copies" :error="form.errors.coe_copies" />
            <Field id="auth_copies" v-model="form.auth_copies" type="number" label="Authentication copies" :error="form.errors.auth_copies" />
            <Field id="official_receipt" v-model="form.official_receipt" label="OR number" required :error="form.errors.official_receipt" />
            <Field id="or_date" v-model="form.or_date" type="date" label="OR date" :error="form.errors.or_date" />
            <div class="flex flex-col justify-end rounded-md bg-surface-muted px-4 py-2.5">
                <span class="text-xs text-text-muted">Amount</span>
                <strong class="text-lg tabular-nums text-text">₱{{ formatNumber(price) }}</strong>
            </div>
        </div>
        <p class="text-sm text-text-muted">₱{{ options.prices.coe }} per certificate and ₱{{ options.prices.auth }} per authentication. The amount is computed when you save.</p>

        <div class="grid gap-4 sm:grid-cols-2">
            <FormGroup v-for="item in slots" :id="`attachment_${item.slot}`" :key="item.slot" :label="item.label" :error="form.errors[`attachment_${item.slot}`]">
                <p v-if="readonly" class="text-sm text-text-muted">{{ onFile(item.slot) ? 'On record: open it from the attachments panel.' : 'No file.' }}</p>
                <FileInput v-if="!readonly" :id="`attachment_${item.slot}`" v-model="form[`attachment_${item.slot}`]" accept=".pdf,.jpg,.jpeg,.png" :invalid="!!form.errors[`attachment_${item.slot}`]" />
                <p v-if="!readonly" class="mt-1.5 text-xs text-text-muted">
                    {{ onFile(item.slot) ? 'A file is on record; uploading replaces it.' : 'No file yet.' }}
                    PDF, JPG or PNG, up to {{ Math.round(options.maxKb / 1024) }} MB.
                </p>
            </FormGroup>
        </div>
    </fieldset>
</template>
