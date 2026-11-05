<script setup>
import { computed } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import KioskLayout from '@/Layouts/KioskLayout.vue';
import KButton from '@/Components/Kiosk/KButton.vue';
import KioskIcon from '@/Components/Kiosk/KioskIcon.vue';

/**
 * The screen of a device that has not been set up. It asks for the one-time code
 * ESD issues (Administrators, Kiosks). No password is ever typed here.
 */
const page = usePage();
const base = computed(() => page.props.kiosk.base);

const form = useForm({ code: '' });

// Shown the way it is printed: two groups of four, letters upper case.
const onInput = (event) => {
    const raw = event.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 8);
    form.code = raw.length > 4 ? `${raw.slice(0, 4)}-${raw.slice(4)}` : raw;
};

const submit = () => form.post(`${base.value}/setup`, { preserveScroll: true, onFinish: () => form.reset() });
</script>

<template>
    <Head title="Set up this kiosk" />

    <KioskLayout>
        <div class="mx-auto max-w-xl pt-4 sm:pt-10">
            <div class="chart-rise relative overflow-hidden rounded-[2rem] border border-white bg-white/90 p-8 shadow-[0_30px_60px_-30px_rgb(42_51_143/0.45)] backdrop-blur sm:p-12">
                <div class="hero-solid absolute inset-x-0 top-0 h-2" aria-hidden="true" />

                <span class="mb-6 flex size-16 items-center justify-center rounded-2xl bg-csc-blue text-white shadow-lg">
                    <KioskIcon name="desktop" :size="32" />
                </span>

                <h1 class="text-3xl font-semibold tracking-tight text-text sm:text-4xl">Set up this kiosk</h1>
                <p class="mt-3 text-lg text-text-muted">This screen is not linked to an office yet. Ask the ESD staff for a set-up code and enter it below.</p>

                <form class="mt-8" @submit.prevent="submit">
                    <label for="code" class="mb-2 block text-base font-semibold text-text">Set-up code</label>
                    <input
                        id="code"
                        :value="form.code"
                        type="text"
                        inputmode="text"
                        autocomplete="off"
                        autocapitalize="characters"
                        spellcheck="false"
                        placeholder="ABCD-2345"
                        maxlength="9"
                        class="block h-20 w-full rounded-2xl border-2 bg-surface text-center font-mono text-4xl tracking-[0.35em] text-text shadow-inner transition placeholder:tracking-[0.2em] placeholder:text-text-muted/40 focus:outline-none focus:ring-4 focus:ring-csc-blue-light/25"
                        :class="form.errors.code ? 'border-csc-red' : 'border-border-strong/40 focus:border-csc-blue'"
                        :aria-invalid="!!form.errors.code"
                        aria-describedby="code-help"
                        @input="onInput"
                    />
                    <p v-if="form.errors.code" class="mt-3 flex items-start gap-2 rounded-xl bg-danger-bg px-4 py-3 text-base font-medium text-danger" role="alert">
                        <KioskIcon name="alert" :size="22" class="mt-0.5 shrink-0" />
                        {{ form.errors.code }}
                    </p>
                    <p id="code-help" class="mt-3 text-sm text-text-muted">The code works once and expires a few minutes after it is issued.</p>

                    <KButton type="submit" class="mt-8" block :loading="form.processing" :disabled="form.code.length < 9">
                        Set up this kiosk
                        <KioskIcon name="arrowRight" :size="22" />
                    </KButton>
                </form>
            </div>

            <ol class="mt-8 grid gap-3 text-sm text-text-muted sm:grid-cols-3">
                <li class="flex items-start gap-3 rounded-2xl bg-white/70 p-4"><span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-csc-blue text-xs font-semibold text-white">1</span>Staff sign in to ETTS and open <strong class="font-semibold text-text">Kiosks</strong>.</li>
                <li class="flex items-start gap-3 rounded-2xl bg-white/70 p-4"><span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-csc-blue text-xs font-semibold text-white">2</span>They choose the office and create a code.</li>
                <li class="flex items-start gap-3 rounded-2xl bg-white/70 p-4"><span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-csc-blue text-xs font-semibold text-white">3</span>You enter it here, once. The kiosk remembers itself.</li>
            </ol>
        </div>
    </KioskLayout>
</template>
