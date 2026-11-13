<script setup>
import Field from '@/Components/Forms/Field.vue';

defineProps({
    form: { type: Object, required: true },
    readonly: { type: Boolean, default: false },
});
</script>

<template>
    <fieldset :disabled="readonly">
        <legend class="sr-only">Verification references</legend>
        <p class="mb-4 text-sm text-text-muted">The references from the Master List, ROE or ICSEVS.</p>
        <div class="grid gap-4 sm:grid-cols-3 lg:grid-cols-4">
            <Field id="book" v-model="form.book" label="Book" required :error="form.errors.book" />
            <Field id="page" v-model="form.page" label="Page" required :error="form.errors.page" />
            <Field id="sequence" v-model="form.sequence" label="Sequence" required :error="form.errors.sequence" />
            <Field id="examinee_no" v-model="form.examinee_no" label="Examinee number" required :error="form.errors.examinee_no" />
            <Field id="cert_no" v-model="form.cert_no" label="Certificate number" required :error="form.errors.cert_no" />
            <Field id="rating" v-model="form.rating" label="Rating" required :error="form.errors.rating" />
            <Field id="release_date" v-model="form.release_date" type="date" label="Release date" required :error="form.errors.release_date" />
            <Field id="effectivity_date" v-model="form.effectivity_date" type="date" label="Effectivity date" :error="form.errors.effectivity_date" />
        </div>
    </fieldset>
</template>
