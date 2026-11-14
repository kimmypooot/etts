<script setup>
import { computed, nextTick, reactive, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import KioskLayout from '@/Layouts/KioskLayout.vue';
import KButton from '@/Components/Kiosk/KButton.vue';
import KField from '@/Components/Kiosk/KField.vue';
import KSelect from '@/Components/Kiosk/KSelect.vue';
import KChoice from '@/Components/Kiosk/KChoice.vue';
import KStepper from '@/Components/Kiosk/KStepper.vue';
import KioskIcon from '@/Components/Kiosk/KioskIcon.vue';
import { useIdle } from '@/Composables/useIdle.js';

/**
 * The request form, one short step at a time. Everything lives in memory only:
 * nothing is saved on the device, and a form left alone is thrown away (the
 * idle guard) so no one's answers stay on a shared screen.
 */
const props = defineProps({
    slug: { type: String, required: true },
    type: { type: String, required: true },
    spels: { type: Boolean, required: true },
    eligibilities: { type: Array, required: true },
    // Certification and authentication: the titles and places from Settings.
    issuanceEligibilities: { type: Array, default: () => [] },
    examCenters: { type: Array, default: () => [] },
    purposes: { type: Array, required: true },
});

const page = usePage();
const base = computed(() => page.props.kiosk.base);

const steps = computed(() =>
    props.spels
        ? [{ key: 'privacy', label: 'Privacy' }, { key: 'eligibility', label: 'Eligibility' }, { key: 'you', label: 'About you' }, { key: 'review', label: 'Review' }]
        : [{ key: 'privacy', label: 'Privacy' }, { key: 'record', label: 'Eligibility record' }, { key: 'you', label: 'About you' }, { key: 'request', label: 'Your request' }, { key: 'review', label: 'Review' }],
);

const step = ref(0);
const current = computed(() => steps.value[step.value].key);

const form = useForm({
    transaction_type: props.type,
    privacy_acknowledged: false,
    website: '',
    elig_code: '',
    lname: '', fname: '', mname: '', extn: '', sex: '', mobile: '', email: '',
    current_address: '', civil_status: '', date_of_birth: '', place_of_birth: '',
    employment_status: '', company_name: '', company_address: '',
    purpose_of_request: '', purpose_others: '',
    used_lastname: '', used_firstname: '', used_middlename: '', used_extn: '',
    eligibility: '', date_of_exam_issuance: '', place_of_exam_issuance: '',
});

// ─── Steps and their fields ───────────────────────────────────────────────
const fieldsOf = {
    privacy: ['privacy_acknowledged'],
    eligibility: ['elig_code'],
    you: ['lname', 'fname', 'mname', 'extn', 'sex', 'mobile', 'email', 'civil_status', 'date_of_birth', 'place_of_birth', 'current_address', 'employment_status', 'company_name', 'company_address'],
    record: ['eligibility', 'date_of_exam_issuance', 'place_of_exam_issuance', 'used_lastname', 'used_firstname', 'used_middlename', 'used_extn'],
    request: ['purpose_of_request', 'purpose_others'],
    review: ['form'],
};

const local = reactive({});
const errorOf = (field) => local[field] ?? form.errors[field];

const validate = (key) => {
    Object.keys(local).forEach((field) => delete local[field]);
    const need = (field, message) => !String(form[field] ?? '').trim() && (local[field] = message);

    if (key === 'privacy' && !form.privacy_acknowledged) local.privacy_acknowledged = 'Please read and accept the Data Privacy Notice to continue.';
    if (key === 'eligibility') need('elig_code', 'Please choose the eligibility you are applying for.');
    if (key === 'you') {
        need('lname', 'Please enter your last name.');
        need('fname', 'Please enter your first name.');
        need('sex', 'Please choose your sex.');
        need('mobile', 'Please enter your mobile number.');
        need('email', 'Please enter your email address.');
        if (!props.spels) {
            need('date_of_birth', 'Please enter your date of birth.');
            need('place_of_birth', 'Please enter your place of birth.');
            need('current_address', 'Please enter your current address.');
            need('civil_status', 'Please choose your civil status.');
            need('employment_status', 'Please choose your employment.');
            if (['Employed', 'Self-Employed'].includes(form.employment_status)) {
                need('company_name', 'Please enter your company or agency.');
                need('company_address', 'Please enter your company address.');
            }
        }
    }
    if (key === 'record') {
        need('eligibility', 'Please choose your eligibility.');
        need('date_of_exam_issuance', 'Please enter the date of examination or issuance.');
        need('place_of_exam_issuance', 'Please choose the place of examination or issuance.');
        need('used_lastname', 'Please enter the last name you used.');
        need('used_firstname', 'Please enter the first name you used.');
    }
    if (key === 'request') {
        need('purpose_of_request', 'Please tell us what the request is for.');
        if (form.purpose_of_request === 'Others') need('purpose_others', 'Please tell us what the request is for.');
    }

    return Object.keys(local).length === 0;
};

const focusFirstError = async () => {
    await nextTick();
    document.querySelector('[aria-invalid="true"], [role="alert"]')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
};

const top = () => window.scrollTo({ top: 0, behavior: 'smooth' });

const next = async () => {
    if (!validate(current.value)) return focusFirstError();
    step.value++;
    top();
};
const back = () => {
    step.value = Math.max(0, step.value - 1);
    top();
};
const goTo = (index) => {
    step.value = index;
    top();
};

// ─── Submit ───────────────────────────────────────────────────────────────
const duplicate = computed(() => form.errors.reason === 'spels_duplicate');

const submit = () => {
    form.transform((data) => ({
        ...data,
        ...(props.spels ? {} : { elig_code: '' }),
    })).post(`${base.value}/requests`, {
        forceFormData: true,
        preserveScroll: true,
        onError: (errors) => {
            // Take the visitor to the first step that has something to fix.
            const first = steps.value.findIndex((s) => fieldsOf[s.key].some((field) => errors[field]));
            step.value = first === -1 ? steps.value.length - 1 : first;
            focusFirstError();
        },
    });
};

// ─── A screen nobody is using is cleared ──────────────────────────────────
const leave = () => router.visit(base.value);
const { warning, remaining, stillHere } = useIdle({ timeout: page.props.kiosk.idleSeconds, warnBefore: 20, onTimeout: leave });

// ─── Helpers ──────────────────────────────────────────────────────────────
const featured = computed(() => props.eligibilities.filter((e) => e.featured));
const others = computed(() => props.eligibilities.filter((e) => !e.featured));
const otherSelected = computed(() => others.value.some((e) => e.code === form.elig_code));
const chosenEligibility = computed(() => props.eligibilities.find((e) => e.code === form.elig_code));

const fullName = computed(() => [form.fname, form.mname, form.lname, form.extn].filter(Boolean).join(' '));
const formatDate = (iso) => (iso ? new Date(`${iso}T00:00:00`).toLocaleDateString('en-PH', { year: 'numeric', month: 'long', day: 'numeric' }) : '');
const today = new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Manila' });

const review = computed(() => {
    const rows = [
        { section: 'about', title: 'About you', go: steps.value.findIndex((s) => s.key === 'you'), items: [
            ['Name', fullName.value], ['Sex', form.sex], ['Mobile number', form.mobile], ['Email', form.email],
            ...(props.spels ? [] : [['Civil status', form.civil_status], ['Date of birth', formatDate(form.date_of_birth)], ['Place of birth', form.place_of_birth], ['Address', form.current_address], ['Employment', form.employment_status], ['Company', form.company_name]]),
        ] },
    ];

    if (props.spels) {
        rows.unshift({ section: 'elig', title: 'Eligibility', go: 1, items: [['Applying for', chosenEligibility.value ? `${chosenEligibility.value.name} (${chosenEligibility.value.code})` : '']] });
    } else {
        rows.unshift({ section: 'record', title: 'Eligibility record', go: steps.value.findIndex((s) => s.key === 'record'), items: [
            ['Eligibility', form.eligibility], ['Date of examination / issuance', formatDate(form.date_of_exam_issuance)], ['Place of examination / issuance', form.place_of_exam_issuance],
            ['Name used in Examination / Issuance of Eligibility', [form.used_firstname, form.used_middlename, form.used_lastname, form.used_extn].filter((part) => part && part.toUpperCase() !== 'N/A').join(' ')],
        ] });
        rows.push({ section: 'request', title: 'Your request', go: steps.value.findIndex((s) => s.key === 'request'), items: [
            ['Purpose', form.purpose_of_request === 'Others' ? `Others: ${form.purpose_others}` : form.purpose_of_request],
        ] });
    }

    return rows.map((row) => ({ ...row, items: row.items.filter(([, value]) => value) }));
});
</script>

<template>
    <Head :title="type" />

    <KioskLayout>
        <div class="mx-auto max-w-3xl">
            <div class="mb-6 flex items-center justify-between gap-3">
                <button type="button" class="inline-flex items-center gap-2 rounded-xl px-3 py-2 text-base font-medium text-text-muted hover:bg-white/80 hover:text-text focus:outline-none focus-visible:ring-4 focus-visible:ring-csc-blue-light/40" @click="leave">
                    <KioskIcon name="arrowLeft" :size="20" /> Cancel
                </button>
                <p class="rounded-full bg-white/80 px-4 py-1.5 text-sm font-semibold text-primary shadow-sm">{{ type }}</p>
            </div>

            <div class="rounded-[2rem] border border-white bg-white/90 p-6 shadow-[0_30px_60px_-34px_rgb(42_51_143/0.5)] backdrop-blur sm:p-10">
                <KStepper :steps="steps" :current="step" class="mb-9" @go="goTo" />

                <form novalidate autocomplete="off" @submit.prevent="current === 'review' ? submit() : next()">
                    <!-- A field only a script would fill. -->
                    <div class="absolute -left-[9999px]" aria-hidden="true">
                        <label>Leave this empty <input v-model="form.website" type="text" name="website" tabindex="-1" autocomplete="off" /></label>
                    </div>

                    <!-- 1 · Privacy -->
                    <section v-if="current === 'privacy'" class="chart-rise space-y-6" aria-labelledby="privacy-title">
                        <header>
                            <h1 id="privacy-title" class="text-3xl font-semibold tracking-tight text-text">Before we start</h1>
                            <p class="mt-2 text-lg text-text-muted">A short note about your information.</p>
                        </header>
                        <div class="flex gap-4 rounded-2xl bg-info-bg p-5 sm:p-6" role="note">
                            <KioskIcon name="shield" :size="30" class="mt-0.5 shrink-0 text-primary" />
                            <div class="space-y-2 text-lg leading-relaxed text-text">
                                <h2 class="font-semibold">Data Privacy Notice</h2>
                                <p>In line with Republic Act No. 10173, the Data Privacy Act of 2012, the information you give here is used only to verify and process your request and to contact you about it. It will not be shared with others without your consent.</p>
                            </div>
                        </div>
                        <label class="flex cursor-pointer items-start gap-4 rounded-2xl border-2 p-5 transition has-[:focus-visible]:ring-4 has-[:focus-visible]:ring-csc-blue-light/40" :class="form.privacy_acknowledged ? 'border-csc-blue bg-info-bg/60' : errorOf('privacy_acknowledged') ? 'border-csc-red' : 'border-border-strong/40'">
                            <input v-model="form.privacy_acknowledged" type="checkbox" class="mt-1 size-7 shrink-0 accent-csc-blue" :aria-invalid="!!errorOf('privacy_acknowledged')" />
                            <span class="text-lg font-medium text-text">I have read and understood the Data Privacy Notice.</span>
                        </label>
                        <p v-if="errorOf('privacy_acknowledged')" class="flex items-center gap-2 text-base font-medium text-danger" role="alert"><KioskIcon name="alert" :size="20" />{{ errorOf('privacy_acknowledged') }}</p>
                    </section>

                    <!-- Eligibility (special eligibility only) -->
                    <section v-else-if="current === 'eligibility'" class="chart-rise space-y-6" aria-labelledby="elig-title">
                        <header>
                            <h1 id="elig-title" class="text-3xl font-semibold tracking-tight text-text">Which eligibility?</h1>
                            <p class="mt-2 text-lg text-text-muted">Tap the one you are applying for.</p>
                        </header>
                        <div class="grid gap-4 sm:grid-cols-3">
                            <label
                                v-for="item in featured"
                                :key="item.code"
                                class="relative flex min-h-40 cursor-pointer flex-col justify-between rounded-2xl border-2 p-5 transition duration-150 active:scale-[0.98] has-[:focus-visible]:ring-4 has-[:focus-visible]:ring-csc-blue-light/40"
                                :class="form.elig_code === item.code ? 'border-csc-blue bg-info-bg shadow-md' : 'border-border-strong/40 bg-surface hover:border-csc-blue-light hover:shadow'"
                            >
                                <input v-model="form.elig_code" type="radio" name="elig" :value="item.code" class="sr-only" />
                                <span class="inline-flex w-fit rounded-lg px-3 py-1 text-lg font-semibold" :class="form.elig_code === item.code ? 'bg-csc-blue text-white' : 'bg-surface-muted text-primary'">{{ item.code }}</span>
                                <span class="mt-4 text-lg leading-snug font-medium text-text">{{ item.name }}</span>
                                <KioskIcon v-if="form.elig_code === item.code" name="check" :size="26" :stroke="2.6" class="absolute top-4 right-4 text-csc-blue" />
                            </label>
                        </div>
                        <div v-if="others.length">
                            <label for="other-elig" class="mb-1.5 block text-base font-semibold text-text">Another special eligibility</label>
                            <select
                                id="other-elig"
                                class="block h-14 w-full rounded-2xl border-2 bg-surface px-5 text-lg text-text shadow-sm focus:outline-none focus:ring-4 focus:ring-csc-blue-light/25"
                                :class="otherSelected ? 'border-csc-blue' : 'border-border-strong/40'"
                                :value="otherSelected ? form.elig_code : ''"
                                @change="form.elig_code = $event.target.value"
                            >
                                <option value="">Choose from the list…</option>
                                <option v-for="item in others" :key="item.code" :value="item.code">{{ item.name }} ({{ item.code }})</option>
                            </select>
                        </div>
                        <p v-if="errorOf('elig_code')" class="flex items-center gap-2 text-base font-medium text-danger" role="alert" aria-invalid="true"><KioskIcon name="alert" :size="20" />{{ errorOf('elig_code') }}</p>
                    </section>

                    <!-- Eligibility record (certification and authentication) -->
                    <section v-else-if="current === 'record'" class="chart-rise space-y-6" aria-labelledby="record-title">
                        <header>
                            <h1 id="record-title" class="text-3xl font-semibold tracking-tight text-text">Your eligibility record</h1>
                            <p class="mt-2 text-lg text-text-muted">Tell us about the eligibility on your certificate.</p>
                        </header>
                        <div class="rounded-2xl bg-surface-muted/70 p-5 sm:p-6">
                            <div class="grid gap-5 sm:grid-cols-2">
                                <KSelect v-model="form.eligibility" label="Eligibility" required :options="issuanceEligibilities" class="sm:col-span-2" :error="errorOf('eligibility')" />
                                <KField v-model="form.date_of_exam_issuance" label="Date of Examination / Issuance" type="date" required :max="today" :error="errorOf('date_of_exam_issuance')" />
                                <KSelect v-model="form.place_of_exam_issuance" label="Place of Examination / Issuance" required :options="examCenters" :error="errorOf('place_of_exam_issuance')" />
                            </div>
                            <h2 class="mt-6 mb-1 text-xl font-semibold text-text">Name used in Examination / Issuance of Eligibility</h2>
                            <p class="mb-4 text-base text-text-muted">Exactly as it appears on your certificate, even if it is the same as your name today. Type N/A if you had no middle name or extension.</p>
                            <div class="grid gap-5 sm:grid-cols-2">
                                <KField v-model="form.used_lastname" label="Last name used" required :error="errorOf('used_lastname')" />
                                <KField v-model="form.used_firstname" label="First name used" required :error="errorOf('used_firstname')" />
                                <KField v-model="form.used_middlename" label="Middle name used" hint="Optional" :error="errorOf('used_middlename')" />
                                <KField v-model="form.used_extn" label="Extension used" hint="Optional. Jr., Sr., III" :error="errorOf('used_extn')" />
                            </div>
                        </div>
                    </section>

                    <!-- About you -->
                    <section v-else-if="current === 'you'" class="chart-rise space-y-6" aria-labelledby="you-title">
                        <header>
                            <h1 id="you-title" class="text-3xl font-semibold tracking-tight text-text">About you</h1>
                            <p class="mt-2 text-lg text-text-muted">Please use your name as it appears on your records.</p>
                        </header>
                        <div class="rounded-2xl bg-surface-muted/70 p-5 sm:p-6">
                            <h2 class="mb-4 text-xl font-semibold text-text">Primary information</h2>
                            <div class="grid gap-5 sm:grid-cols-2">
                                <KField v-model="form.lname" label="Last name" required :error="errorOf('lname')" />
                                <KField v-model="form.fname" label="First name" required :error="errorOf('fname')" />
                                <KField v-model="form.mname" label="Middle name" hint="Optional" :error="errorOf('mname')" />
                                <KField v-model="form.extn" label="Extension" hint="Optional. Jr., Sr., III" :maxlength="10" :error="errorOf('extn')" />
                                <template v-if="!spels">
                                    <KField v-model="form.date_of_birth" label="Date of birth" type="date" required :max="today" :error="errorOf('date_of_birth')" />
                                    <KField v-model="form.place_of_birth" label="Place of birth" required :error="errorOf('place_of_birth')" />
                                </template>
                            </div>
                        </div>

                        <div class="space-y-5">
                            <h2 class="text-xl font-semibold text-text">Other details</h2>
                            <KChoice v-model="form.sex" label="Sex" required :options="['Male', 'Female']" :error="errorOf('sex')" />
                            <div class="grid gap-5 sm:grid-cols-2">
                                <KField v-model="form.mobile" label="Mobile number" required type="tel" inputmode="tel" :maxlength="12" hint="So we can reach you about your request." :error="errorOf('mobile')" />
                                <KField v-model="form.email" label="Email address" required type="email" inputmode="email" :error="errorOf('email')" />
                            </div>
                            <template v-if="!spels">
                                <KField v-model="form.current_address" label="Current address" multiline required :maxlength="500" :error="errorOf('current_address')" />
                                <KChoice v-model="form.civil_status" label="Civil status" required :options="['Single', 'Married', 'Widowed', 'Separated']" columns="sm:grid-cols-4" :error="errorOf('civil_status')" />
                                <KChoice v-model="form.employment_status" label="Employment" required :options="['Employed', 'Self-Employed', 'Unemployed']" columns="sm:grid-cols-3" :error="errorOf('employment_status')" />
                                <div v-if="form.employment_status === 'Employed' || form.employment_status === 'Self-Employed'" class="grid gap-5 sm:grid-cols-2">
                                    <KField v-model="form.company_name" label="Company or agency" required :error="errorOf('company_name')" />
                                    <KField v-model="form.company_address" label="Company address" required :error="errorOf('company_address')" />
                                </div>
                            </template>
                        </div>
                    </section>

                    <!-- Your request (certification and authentication) -->
                    <section v-else-if="current === 'request'" class="chart-rise space-y-6" aria-labelledby="req-title">
                        <header>
                            <h1 id="req-title" class="text-3xl font-semibold tracking-tight text-text">Your request</h1>
                            <p class="mt-2 text-lg text-text-muted">Tell us what it is for.</p>
                        </header>
                        <KChoice v-model="form.purpose_of_request" label="What is the request for?" required :options="[...purposes, 'Others']" columns="sm:grid-cols-2" :error="errorOf('purpose_of_request')" />
                        <KField v-if="form.purpose_of_request === 'Others'" v-model="form.purpose_others" label="Please tell us more" required :maxlength="80" :error="errorOf('purpose_others')" />
                    </section>

                    <!-- Review -->
                    <section v-else class="chart-rise space-y-6" aria-labelledby="review-title">
                        <header>
                            <h1 id="review-title" class="text-3xl font-semibold tracking-tight text-text">Please check your answers</h1>
                            <p class="mt-2 text-lg text-text-muted">If everything is right, tap Submit. You can change anything first.</p>
                        </header>

                        <div v-if="duplicate" class="rounded-2xl border-2 border-warning-border bg-warning-bg p-6" role="alert">
                            <h2 class="flex items-center gap-2 text-xl font-semibold text-warning"><KioskIcon name="alert" :size="26" /> We could not accept this application</h2>
                            <p class="mt-2 text-lg leading-relaxed text-text">{{ form.errors.form }}</p>
                            <KButton class="mt-5" variant="secondary" :href="base">Choose another service</KButton>
                        </div>
                        <div v-else-if="form.errors.form" class="flex items-start gap-3 rounded-2xl bg-danger-bg p-5 text-lg font-medium text-danger" role="alert"><KioskIcon name="alert" :size="26" class="shrink-0" />{{ form.errors.form }}</div>

                        <div v-for="block in review" :key="block.section" class="rounded-2xl border border-border bg-surface p-5 sm:p-6">
                            <div class="mb-3 flex items-center justify-between gap-3">
                                <h2 class="text-xl font-semibold text-text">{{ block.title }}</h2>
                                <button type="button" class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-base font-semibold text-primary hover:bg-info-bg focus:outline-none focus-visible:ring-4 focus-visible:ring-csc-blue-light/40" @click="goTo(block.go)"><KioskIcon name="edit" :size="18" /> Change</button>
                            </div>
                            <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                                <div v-for="[label, value] in block.items" :key="label" class="min-w-0">
                                    <dt class="text-sm text-text-muted">{{ label }}</dt>
                                    <dd class="text-lg font-medium break-words text-text">{{ value }}</dd>
                                </div>
                            </dl>
                        </div>
                    </section>

                    <div class="mt-9 flex items-center justify-between gap-4">
                        <KButton v-if="step > 0" variant="secondary" @click="back"><KioskIcon name="arrowLeft" :size="22" /> Back</KButton>
                        <span v-else />
                        <KButton v-if="current !== 'review'" type="submit">Next <KioskIcon name="arrowRight" :size="22" /></KButton>
                        <KButton v-else type="submit" :loading="form.processing" :disabled="duplicate">
                            Submit my request <KioskIcon name="check" :size="22" :stroke="2.4" />
                        </KButton>
                    </div>
                </form>
            </div>
        </div>

        <!-- "Still there?" -->
        <Teleport to="body">
            <div v-if="warning" class="fixed inset-0 z-50 flex items-center justify-center bg-text/60 p-6 backdrop-blur-sm" role="alertdialog" aria-modal="true" aria-labelledby="idle-title">
                <div class="chart-rise w-full max-w-lg rounded-[2rem] bg-surface p-9 text-center shadow-2xl">
                    <span class="mx-auto mb-5 flex size-20 items-center justify-center rounded-full bg-warning-bg text-warning"><KioskIcon name="clock" :size="40" /></span>
                    <h2 id="idle-title" class="text-3xl font-semibold text-text">Are you still there?</h2>
                    <p class="mt-3 text-lg text-text-muted">To protect your information, this form will be cleared in <strong class="text-3xl font-semibold text-csc-red tabular-nums">{{ remaining }}</strong> seconds.</p>
                    <KButton class="mt-7" block @click="stillHere">I am still here</KButton>
                </div>
            </div>
        </Teleport>
    </KioskLayout>
</template>
