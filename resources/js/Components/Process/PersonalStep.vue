<script setup>
import { computed } from 'vue';
import Field from '@/Components/Forms/Field.vue';
import FormGroup from '@/Components/Forms/FormGroup.vue';
import Select from '@/Components/Forms/Select.vue';
import { fieldClass, civilOptions, employmentOptions, sexOptions } from './options.js';

const props = defineProps({
    form: { type: Object, required: true },
    options: { type: Object, required: true },
    readonly: { type: Boolean, default: false },
});

const purposeOptions = computed(() => [
    { value: '', label: '—' },
    ...props.options.purposes.map((value) => ({ value, label: value })),
    { value: 'Others', label: 'Others' },
]);
</script>

<template>
    <fieldset :disabled="readonly" class="space-y-6">
        <legend class="sr-only">Personal information</legend>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Field id="lname" v-model="form.lname" label="Last name" required :error="form.errors.lname" />
            <Field id="fname" v-model="form.fname" label="First name" required :error="form.errors.fname" />
            <Field id="mname" v-model="form.mname" label="Middle name" :error="form.errors.mname" />
            <Field id="extn" v-model="form.extn" label="Extension (Jr., III)" :error="form.errors.extn" />
            <Field id="date_of_birth" v-model="form.date_of_birth" type="date" label="Date of birth" :error="form.errors.date_of_birth" />
            <Field id="place_of_birth" v-model="form.place_of_birth" label="Place of birth" :error="form.errors.place_of_birth" />
            <FormGroup id="sex" label="Sex" :error="form.errors.sex">
                <Select id="sex" v-model="form.sex" :options="sexOptions" />
            </FormGroup>
            <FormGroup id="civil_status" label="Civil status" :error="form.errors.civil_status">
                <Select id="civil_status" v-model="form.civil_status" :options="civilOptions" />
            </FormGroup>
            <FormGroup id="current_address" label="Current address" :error="form.errors.current_address" class="sm:col-span-2 lg:col-span-4">
                <textarea id="current_address" v-model="form.current_address" rows="2" maxlength="500" :class="fieldClass" />
            </FormGroup>
            <Field id="mobile" v-model="form.mobile" label="Mobile number" :error="form.errors.mobile" />
            <Field id="email" v-model="form.email" type="email" label="Email" :error="form.errors.email" class="lg:col-span-3" />
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <FormGroup id="purpose_of_request" label="Purpose of request" required :error="form.errors.purpose_of_request" class="lg:col-span-2">
                <Select id="purpose_of_request" v-model="form.purpose_of_request" :options="purposeOptions" />
            </FormGroup>
            <Field
                v-if="form.purpose_of_request === 'Others'"
                id="purpose_others"
                v-model="form.purpose_others"
                label="Please specify"
                required
                :error="form.errors.purpose_others"
                class="lg:col-span-2"
            />
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <FormGroup id="employment_status" label="Employment status" :error="form.errors.employment_status">
                <Select id="employment_status" v-model="form.employment_status" :options="employmentOptions" />
            </FormGroup>
            <Field id="company_name" v-model="form.company_name" label="Company / agency" :error="form.errors.company_name" />
            <Field id="company_address" v-model="form.company_address" label="Company address" :error="form.errors.company_address" class="sm:col-span-2" />
        </div>
    </fieldset>
</template>
