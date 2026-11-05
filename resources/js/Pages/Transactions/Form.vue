<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import StaffLayout from '@/Layouts/StaffLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import Alert from '@/Components/Feedback/Alert.vue';
import Select from '@/Components/Forms/Select.vue';
import Field from '@/Components/Forms/Field.vue';
import PersonInput from '@/Components/Forms/PersonInput.vue';
import FormGroup from '@/Components/Forms/FormGroup.vue';
import FileInput from '@/Components/Forms/FileInput.vue';
import TransactionStatusBadge from '@/Components/Transactions/TransactionStatusBadge.vue';
import { formatNumber } from '@/Utils/format.js';

const props = defineProps({
    mode: { type: String, required: true },
    id: { type: Number, default: null },
    status: { type: String, default: null },
    version: { type: String, default: null },
    record: { type: Object, required: true },
    attachments: { type: Array, default: () => [] },
    options: { type: Object, required: true },
    base: { type: String, default: '/admin/transactions' },
    officeLocked: { type: Boolean, default: false },
});

const editing = props.mode === 'edit';

// Printing (opens the legacy-format documents in their own tab).
const signatory = ref({ name: '', position: '', office: 'Examination Services Division' });
const hasOriginal = computed(() => props.attachments.some((a) => a.slot === 2));
const isAuthType = computed(() => form.transaction_type.includes('Authentication'));
const openPrint = (path, query = '') => window.open(`${props.base}/${props.id}/${path}${query}`, '_blank', 'noopener');
const printRequestForm = () => openPrint('errf');
const printAuthenticatedCopy = () => {
    if (!signatory.value.name.trim()) return;
    openPrint('authenticated-copy', '?' + new URLSearchParams({ signatory: signatory.value.name, position: signatory.value.position, office: signatory.value.office }));
};

const blank = {
    transaction_type: 'Special Eligibility Application',
    office_id: '',
    lname: '',
    fname: '',
    mname: '',
    extn: '',
    sex: '',
    mobile: '',
    email: '',
    transaction_date: '',
    elig_code: '',
    official_receipt: '',
    or_date: '',
    amount: '',
    remarks: '',
    transaction_details: '',
    coe_copies: 0,
    auth_copies: 0,
    current_address: '',
    civil_status: '',
    date_of_birth: '',
    place_of_birth: '',
    purpose_of_request: '',
    purpose_others: '',
    employment_status: '',
    company_name: '',
    company_address: '',
    used_lastname: '',
    used_firstname: '',
    used_middlename: '',
    used_extn: '',
    used_address: '',
    eligibility: '',
    date_of_exam_issuance: '',
    place_of_exam_issuance: '',
    book: '',
    page: '',
    sequence: '',
    examinee_no: '',
    cert_no: '',
    rating: '',
    effectivity_date: '',
    release_date: '',
    attachment_1: null,
    attachment_2: null,
};

// null from the server becomes '' so every input stays controlled.
const initial = Object.fromEntries(Object.entries({ ...blank, ...props.record }).map(([key, value]) => [key, value ?? (blank[key] ?? '')]));
const form = useForm({ ...initial, version: props.version });

const isSpels = computed(() => form.transaction_type === 'Special Eligibility Application');
const isIssuance = computed(() => !isSpels.value);
const price = computed(() => (Number(form.coe_copies) || 0) * props.options.prices.coe + (Number(form.auth_copies) || 0) * props.options.prices.auth);

// A SPELS application cannot change into a certification (or the reverse) once filed.
const typeChoices = computed(() =>
    props.options.types.filter((type) => !editing || (type.value === 'Special Eligibility Application') === isSpelsAtLoad),
);
const isSpelsAtLoad = props.record.transaction_type === 'Special Eligibility Application';

const sexOptions = [{ value: '', label: '—' }, { value: 'Male', label: 'Male' }, { value: 'Female', label: 'Female' }];
const civilOptions = ['', 'Single', 'Married', 'Widowed', 'Separated', 'Annulled'].map((value) => ({ value, label: value || '—' }));
const employmentOptions = ['', 'Employed', 'Unemployed', 'Self-Employed', 'Student', 'Retired'].map((value) => ({ value, label: value || '—' }));
const purposeOptions = computed(() => [
    { value: '', label: '—' },
    ...props.options.purposes.map((value) => ({ value, label: value })),
    { value: 'Others', label: 'Others' },
]);
const eligibilityOptions = computed(() => [{ value: '', label: 'Choose an eligibility…' }, ...props.options.eligibilities]);

const submit = () => {
    const options = { preserveScroll: true, forceFormData: true };

    if (editing) {
        form.transform((data) => ({ ...data, _method: 'put' })).post(`${props.base}/${props.id}`, options);
    } else {
        form.post(props.base, options);
    }
};

const walkIn = computed(() => editing && isSpels.value && props.status === 'submitted');

const textareaClass =
    'block w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-sm text-text shadow-xs focus:border-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring';
const sectionClass = 'mb-6 rounded-lg border border-border bg-surface p-5';
const headingClass = 'mb-4 text-base font-semibold text-text';
</script>

<template>
    <Head :title="editing ? `Edit transaction #${id}` : 'New transaction'" />

    <StaffLayout>
        <PageHeader
            :title="editing ? `Edit transaction #${id}` : 'New transaction'"
            :breadcrumbs="[{ label: 'Dashboard', href: '/dashboard' }, { label: 'Transactions', href: props.base }, { label: editing ? `#${id}` : 'New' }]"
        >
            <template v-if="editing" #actions>
                <TransactionStatusBadge :status="status" />
            </template>
        </PageHeader>

        <Alert v-if="Object.keys(form.errors).length" tone="danger" class="mb-6">
            Please correct the highlighted fields.
        </Alert>
        <Alert v-if="walkIn" tone="info" class="mb-6">
            This application was filed at ESD and has not been received yet. Saving it with an OR number confirms the walk-in and marks it received.
        </Alert>

        <form class="max-w-5xl" @submit.prevent="submit">
            <section :class="sectionClass" aria-labelledby="sec-applicant">
                <h2 id="sec-applicant" :class="headingClass">Applicant</h2>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Field id="lname" v-model="form.lname" label="Last name" required :error="form.errors.lname" />
                    <Field id="fname" v-model="form.fname" label="First name" required :error="form.errors.fname" />
                    <Field id="mname" v-model="form.mname" label="Middle name" :error="form.errors.mname" />
                    <Field id="extn" v-model="form.extn" label="Extension (Jr., III)" :error="form.errors.extn" />
                    <FormGroup id="sex" label="Sex" :error="form.errors.sex">
                        <Select id="sex" v-model="form.sex" :options="sexOptions" />
                    </FormGroup>
                    <Field id="mobile" v-model="form.mobile" label="Mobile number" :error="form.errors.mobile" />
                    <Field id="email" v-model="form.email" type="email" label="Email" :error="form.errors.email" class="sm:col-span-2" />
                </div>
            </section>

            <section :class="sectionClass" aria-labelledby="sec-transaction">
                <h2 id="sec-transaction" :class="headingClass">Transaction</h2>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <FormGroup id="transaction_type" label="Type" required :error="form.errors.transaction_type">
                        <Select id="transaction_type" v-model="form.transaction_type" :options="typeChoices" />
                    </FormGroup>
                    <FormGroup id="office_id" label="Office" required :error="form.errors.office_id">
                        <Select id="office_id" v-model="form.office_id" :options="options.offices" :disabled="officeLocked" />
                    </FormGroup>
                    <Field id="transaction_date" v-model="form.transaction_date" type="date" label="Date filed" required :error="form.errors.transaction_date" />
                    <FormGroup v-if="isSpels" id="elig_code" label="Eligibility" required :error="form.errors.elig_code" class="sm:col-span-2">
                        <Select id="elig_code" v-model="form.elig_code" :options="eligibilityOptions" />
                    </FormGroup>
                </div>
                <Alert v-if="isSpels" tone="info" class="mt-4">
                    An applicant may hold only one approved application per eligibility. If one is already on file, saving is refused.
                </Alert>
            </section>

            <section :class="sectionClass" aria-labelledby="sec-payment">
                <h2 id="sec-payment" :class="headingClass">Payment</h2>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Field id="official_receipt" v-model="form.official_receipt" label="OR number" :error="form.errors.official_receipt" />
                    <Field id="or_date" v-model="form.or_date" type="date" label="OR date" :error="form.errors.or_date" />
                    <Field v-if="isSpels" id="amount" v-model="form.amount" type="number" label="Amount (₱)" :error="form.errors.amount" />
                    <template v-else>
                        <Field id="coe_copies" v-model="form.coe_copies" type="number" label="Certificate copies" :error="form.errors.coe_copies" />
                        <Field id="auth_copies" v-model="form.auth_copies" type="number" label="Authentication copies" :error="form.errors.auth_copies" />
                    </template>
                </div>
                <p v-if="isIssuance" class="mt-3 text-sm text-text-muted">
                    ₱{{ options.prices.coe }} per certificate and ₱{{ options.prices.auth }} per authentication.
                    <template v-if="price > 0"><strong class="text-text">Total ₱{{ formatNumber(price) }}</strong>.</template>
                    <template v-else-if="editing">Leave both at 0 to keep the amount already on file.</template>
                </p>
            </section>

            <section v-if="isIssuance" :class="sectionClass" aria-labelledby="sec-issuance">
                <h2 id="sec-issuance" :class="headingClass">Request form</h2>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <FormGroup id="current_address" label="Current address" :error="form.errors.current_address" class="sm:col-span-2 lg:col-span-3">
                        <textarea id="current_address" v-model="form.current_address" rows="2" maxlength="500" :class="textareaClass" />
                    </FormGroup>
                    <FormGroup id="civil_status" label="Civil status" :error="form.errors.civil_status">
                        <Select id="civil_status" v-model="form.civil_status" :options="civilOptions" />
                    </FormGroup>
                    <Field id="date_of_birth" v-model="form.date_of_birth" type="date" label="Date of birth" :error="form.errors.date_of_birth" />
                    <Field id="place_of_birth" v-model="form.place_of_birth" label="Place of birth" :error="form.errors.place_of_birth" />
                    <FormGroup id="employment_status" label="Employment status" :error="form.errors.employment_status">
                        <Select id="employment_status" v-model="form.employment_status" :options="employmentOptions" />
                    </FormGroup>
                    <Field id="company_name" v-model="form.company_name" label="Company / agency" :error="form.errors.company_name" />
                    <Field id="company_address" v-model="form.company_address" label="Company address" :error="form.errors.company_address" />
                    <FormGroup id="purpose_of_request" label="Purpose of request" :error="form.errors.purpose_of_request">
                        <Select id="purpose_of_request" v-model="form.purpose_of_request" :options="purposeOptions" />
                    </FormGroup>
                    <Field v-if="form.purpose_of_request === 'Others'" id="purpose_others" v-model="form.purpose_others" label="Please specify" :error="form.errors.purpose_others" class="lg:col-span-2" />
                </div>

                <h3 class="mt-6 mb-3 text-sm font-semibold text-text">Name used when the eligibility was granted (if different)</h3>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Field id="used_lastname" v-model="form.used_lastname" label="Last name" :error="form.errors.used_lastname" />
                    <Field id="used_firstname" v-model="form.used_firstname" label="First name" :error="form.errors.used_firstname" />
                    <Field id="used_middlename" v-model="form.used_middlename" label="Middle name" :error="form.errors.used_middlename" />
                    <Field id="used_extn" v-model="form.used_extn" label="Extension" :error="form.errors.used_extn" />
                </div>

                <h3 class="mt-6 mb-3 text-sm font-semibold text-text">Eligibility record</h3>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Field id="eligibility" v-model="form.eligibility" label="Eligibility" :error="form.errors.eligibility" class="lg:col-span-2" />
                    <Field id="date_of_exam_issuance" v-model="form.date_of_exam_issuance" type="date" label="Date of exam / issuance" :error="form.errors.date_of_exam_issuance" />
                    <Field id="place_of_exam_issuance" v-model="form.place_of_exam_issuance" label="Place of exam / issuance" :error="form.errors.place_of_exam_issuance" />
                    <Field id="book" v-model="form.book" label="Book" :error="form.errors.book" />
                    <Field id="page" v-model="form.page" label="Page" :error="form.errors.page" />
                    <Field id="sequence" v-model="form.sequence" label="Sequence" :error="form.errors.sequence" />
                    <Field id="examinee_no" v-model="form.examinee_no" label="Examinee number" :error="form.errors.examinee_no" />
                    <Field id="cert_no" v-model="form.cert_no" label="Certificate number" :error="form.errors.cert_no" />
                    <Field id="rating" v-model="form.rating" label="Rating" :error="form.errors.rating" />
                    <Field id="effectivity_date" v-model="form.effectivity_date" type="date" label="Effectivity date" :error="form.errors.effectivity_date" />
                    <Field id="release_date" v-model="form.release_date" type="date" label="Release date" :error="form.errors.release_date" />
                </div>
            </section>

            <section v-if="isIssuance" :class="sectionClass" aria-labelledby="sec-attachments">
                <h2 id="sec-attachments" :class="headingClass">Attachments</h2>
                <p class="mb-4 text-sm text-text-muted">PDF, JPG or PNG, up to {{ Math.round(options.maxKb / 1024) }} MB each. Uploading a file replaces the one on record. Files are private and every view is logged.</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormGroup v-for="slot in [1, 2]" :id="`attachment_${slot}`" :key="slot" :label="`Attachment ${slot}`" :error="form.errors[`attachment_${slot}`]">
                        <FileInput :id="`attachment_${slot}`" v-model="form[`attachment_${slot}`]" accept=".pdf,.jpg,.jpeg,.png" :invalid="!!form.errors[`attachment_${slot}`]" />
                        <a
                            v-for="existing in attachments.filter((a) => a.slot === slot)"
                            :key="existing.slot"
                            :href="existing.url"
                            target="_blank"
                            rel="noopener"
                            class="mt-2 inline-flex items-center gap-1.5 text-sm font-medium text-primary hover:underline"
                        >
                            <Icon name="externalLink" size="sm" />
                            View current file ({{ existing.type.toUpperCase() }})
                        </a>
                    </FormGroup>
                </div>
            </section>

            <section v-if="editing && isIssuance" :class="sectionClass" aria-labelledby="sec-print">
                <h2 id="sec-print" :class="headingClass">Printing</h2>
                <p class="mb-4 text-sm text-text-muted">Print from the saved record. Save your changes first.</p>
                <div class="grid gap-6 lg:grid-cols-2">
                    <div>
                        <h3 class="mb-1 text-sm font-semibold text-text">Request form (ERRF)</h3>
                        <p class="mb-3 text-sm text-text-muted">The Eligibility/Exam Records Request Form with the applicant's details, the master-list data and the verifier boxes.</p>
                        <Button type="button" variant="secondary" @click="printRequestForm"><Icon name="printer" size="sm" />Print request form</Button>
                    </div>
                    <div v-if="isAuthType">
                        <h3 class="mb-1 text-sm font-semibold text-text">Authenticated copy</h3>
                        <p v-if="!hasOriginal" class="text-sm text-text-muted">Upload the original document as Attachment 2 and save, to print the authenticated copy.</p>
                        <template v-else>
                            <p class="mb-3 text-sm text-text-muted">Prints Attachment 2 with the "Certified Authenticated Copy" stamp, the O.R. number and a QR code of this record.</p>
                            <div class="mb-3 grid gap-3 sm:grid-cols-3">
                                <PersonInput
                                    id="signatory-name"
                                    v-model="signatory.name"
                                    placeholder="Signatory"
                                    aria-label="Signatory"
                                    :input-class="textareaClass"
                                    @pick="(p) => { if (p.position) signatory.position = p.position; if (p.office) signatory.office = p.office; }"
                                />
                                <input v-model="signatory.position" type="text" placeholder="Position" aria-label="Position" :class="textareaClass" />
                                <input v-model="signatory.office" type="text" placeholder="Office" aria-label="Office" :class="textareaClass" />
                            </div>
                            <Button type="button" variant="secondary" :disabled="!signatory.name.trim()" @click="printAuthenticatedCopy"><Icon name="printer" size="sm" />Print authenticated copy</Button>
                        </template>
                    </div>
                </div>
            </section>

            <section :class="sectionClass" aria-labelledby="sec-notes">
                <h2 id="sec-notes" :class="headingClass">Notes</h2>
                <div class="grid gap-4">
                    <FormGroup id="transaction_details" label="Details" :error="form.errors.transaction_details">
                        <textarea id="transaction_details" v-model="form.transaction_details" rows="2" maxlength="500" :class="textareaClass" />
                    </FormGroup>
                    <FormGroup id="remarks" label="Remarks" :error="form.errors.remarks">
                        <textarea id="remarks" v-model="form.remarks" rows="3" maxlength="1000" :class="textareaClass" />
                    </FormGroup>
                </div>
            </section>

            <div class="flex items-center justify-end gap-2">
                <Button variant="secondary" :href="props.base">Cancel</Button>
                <Button type="submit" :loading="form.processing">{{ editing ? 'Save changes' : 'Create transaction' }}</Button>
            </div>
        </form>
    </StaffLayout>
</template>
