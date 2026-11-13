<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3';
import Alert from '@/Components/Feedback/Alert.vue';
import Button from '@/Components/UI/Button.vue';

// ETTS has no sign-in form: staff open it from IMIS. Refused sign-ins and
// sign-outs land here with the reason.
defineProps({
    imisUrl: { type: String, default: null },
    // Only sent in local development (ETTS_DEV_SIGN_IN): { offices: [{ id, name }] }.
    devSignIn: { type: Object, default: null },
});

const dev = useForm({ role: 'admin', office_id: '' });

const page = usePage();
const logo = '/images/csc-logo.png';
</script>

<template>
    <Head title="Sign in" />

    <div class="flex min-h-dvh flex-col bg-csc-blue">
        <main class="flex flex-1 items-center justify-center px-4 py-8 sm:px-6 lg:p-10">
            <div class="w-full max-w-md rounded-2xl border border-border bg-surface px-6 py-9 shadow-sm sm:px-10">
                <div class="mb-6 flex items-center gap-4">
                    <img :src="logo" alt="" class="h-11 w-auto shrink-0 select-none" draggable="false" />
                    <div>
                        <p class="text-base leading-snug font-semibold text-primary">ETTS</p>
                        <p class="text-xs text-text-muted">ESD Transactions Tracking System</p>
                    </div>
                </div>

                <Alert v-if="page.props.flash.error" tone="danger" class="mb-5">{{ page.props.flash.error }}</Alert>
                <Alert v-else-if="page.props.flash.status" tone="success" class="mb-5">{{ page.props.flash.status }}</Alert>

                <h1 class="text-xl font-semibold text-text">Sign in through IMIS</h1>
                <p class="mt-2 text-sm text-text-muted">
                    ETTS has no separate password. Open it from your IMIS portal, under the systems you have access to.
                </p>

                <Button v-if="imisUrl" :href="imisUrl" external class="mt-6 w-full justify-center">Go to IMIS</Button>

                <form
                    v-if="devSignIn"
                    class="mt-8 space-y-3 border-t border-border pt-6"
                    @submit.prevent="dev.post('/dev/sign-in')"
                >
                    <p class="text-sm font-semibold text-text">Local development only</p>
                    <p class="text-xs text-text-muted">Skips IMIS. Not available in production.</p>
                    <select v-model="dev.role" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                        <option value="admin">Administrator</option>
                        <option value="field_office">Field Office</option>
                    </select>
                    <select v-model="dev.office_id" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm">
                        <option value="">{{ dev.role === 'admin' ? 'No office' : 'Choose an office' }}</option>
                        <option v-for="office in devSignIn.offices" :key="office.id" :value="office.id">{{ office.name }}</option>
                    </select>
                    <p v-if="dev.errors.office_id" class="text-xs text-danger">{{ dev.errors.office_id }}</p>
                    <Button type="submit" :disabled="dev.processing" class="w-full justify-center">Sign in as dev user</Button>
                </form>
            </div>
        </main>
    </div>
</template>
