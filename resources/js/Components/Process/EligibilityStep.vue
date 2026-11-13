<script setup>
import Field from '@/Components/Forms/Field.vue';

defineProps({
    form: { type: Object, required: true },
    readonly: { type: Boolean, default: false },
});
</script>

<template>
    <fieldset :disabled="readonly" class="space-y-6">
        <legend class="sr-only">Eligibility</legend>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Field id="eligibility" v-model="form.eligibility" label="Eligibility" required :error="form.errors.eligibility" class="sm:col-span-2" />
            <Field id="date_of_exam_issuance" v-model="form.date_of_exam_issuance" type="date" label="Date of exam / issuance" required :error="form.errors.date_of_exam_issuance" />
            <Field id="place_of_exam_issuance" v-model="form.place_of_exam_issuance" label="Place of exam / issuance" required :error="form.errors.place_of_exam_issuance" />
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
