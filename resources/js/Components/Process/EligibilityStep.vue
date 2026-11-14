<script setup>
import { computed } from 'vue';
import Field from '@/Components/Forms/Field.vue';
import FormGroup from '@/Components/Forms/FormGroup.vue';
import Select from '@/Components/Forms/Select.vue';

const props = defineProps({
    form: { type: Object, required: true },
    options: { type: Object, required: true },
    readonly: { type: Boolean, default: false },
});

// The titles and places from Settings (and the record's own value).
const eligibilityOptions = computed(() => props.options.issuanceEligibilities);
const placeOptions = computed(() => props.options.examCenters);
</script>

<template>
    <fieldset :disabled="readonly" class="space-y-6">
        <legend class="sr-only">Eligibility</legend>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <FormGroup id="eligibility" label="Eligibility" required :error="form.errors.eligibility" class="sm:col-span-2">
                <Select id="eligibility" v-model="form.eligibility" :options="eligibilityOptions" placeholder="Choose an eligibility…" required :invalid="!!form.errors.eligibility" />
            </FormGroup>
            <Field id="date_of_exam_issuance" v-model="form.date_of_exam_issuance" type="date" label="Date of exam / issuance" required :error="form.errors.date_of_exam_issuance" />
            <FormGroup id="place_of_exam_issuance" label="Place of exam / issuance" required :error="form.errors.place_of_exam_issuance">
                <Select id="place_of_exam_issuance" v-model="form.place_of_exam_issuance" :options="placeOptions" placeholder="Choose a place…" required :invalid="!!form.errors.place_of_exam_issuance" />
            </FormGroup>
        </div>

        <div>
            <h3 class="mb-1 text-sm font-semibold text-text">Name used when the eligibility was granted</h3>
            <p class="mb-3 text-sm text-text-muted">Only if it differs from the name above.</p>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Field id="used_lastname" v-model="form.used_lastname" label="Last name" :error="form.errors.used_lastname" />
                <Field id="used_firstname" v-model="form.used_firstname" label="First name" :error="form.errors.used_firstname" />
                <Field id="used_middlename" v-model="form.used_middlename" label="Middle name" :error="form.errors.used_middlename" />
                <Field id="used_extn" v-model="form.used_extn" label="Extension" :error="form.errors.used_extn" />
            </div>
        </div>
    </fieldset>
</template>
