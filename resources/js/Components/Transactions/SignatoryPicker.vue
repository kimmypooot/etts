<script setup>
import PersonInput from '@/Components/Forms/PersonInput.vue';

// Who signs the authenticated copy: name from the IMIS people directory
// (a pick fills position and office), all three editable.
const signatory = defineModel({ type: Object, required: true });

const inputClass =
    'block w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-sm text-text shadow-xs focus:border-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring';

const picked = (person) => {
    if (person.position) signatory.value.position = person.position;
    if (person.office) signatory.value.office = person.office;
};
</script>

<template>
    <div class="grid gap-3 sm:grid-cols-3">
        <PersonInput id="signatory-name" v-model="signatory.name" placeholder="Signatory" aria-label="Signatory" :input-class="inputClass" @pick="picked" />
        <input v-model="signatory.position" type="text" placeholder="Position" aria-label="Position" :class="inputClass" />
        <input v-model="signatory.office" type="text" placeholder="Office" aria-label="Office" :class="inputClass" />
    </div>
</template>
