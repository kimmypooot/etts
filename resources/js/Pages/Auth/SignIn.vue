<script setup>
import { Head, usePage } from '@inertiajs/vue3';
import Alert from '@/Components/Feedback/Alert.vue';
import Button from '@/Components/UI/Button.vue';

// ETTS has no sign-in form: staff open it from IMIS. Refused sign-ins and
// sign-outs land here with the reason.
defineProps({
    imisUrl: { type: String, default: null },
});

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
                        <p class="text-xs text-text-muted">Examination Transaction and Tracking System</p>
                    </div>
                </div>

                <Alert v-if="page.props.flash.error" tone="danger" class="mb-5">{{ page.props.flash.error }}</Alert>
                <Alert v-else-if="page.props.flash.status" tone="success" class="mb-5">{{ page.props.flash.status }}</Alert>

                <h1 class="text-xl font-semibold text-text">Sign in through IMIS</h1>
                <p class="mt-2 text-sm text-text-muted">
                    ETTS has no separate password. Open it from your IMIS portal, under the systems you have access to.
                </p>

                <Button v-if="imisUrl" :href="imisUrl" external class="mt-6 w-full justify-center">Go to IMIS</Button>
            </div>
        </main>
    </div>
</template>
