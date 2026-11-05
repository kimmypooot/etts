<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import StaffLayout from '@/Layouts/StaffLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/UI/Button.vue';
import Icon from '@/Components/UI/Icon.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Select from '@/Components/Forms/Select.vue';
import FormGroup from '@/Components/Forms/FormGroup.vue';
import ChartCard from '@/Components/Dashboard/ChartCard.vue';
import StatCard from '@/Components/Dashboard/StatCard.vue';
import { confirm, toast } from '@/Composables/useDialog';

const props = defineProps({
    terminals: { type: Array, required: true },
    pending: { type: Array, required: true },
    issued: { type: Object, default: null },
    offices: { type: Array, required: true },
    kioskUrl: { type: String, required: true },
    pairingMinutes: { type: Number, required: true },
});

const form = useForm({ office_id: '', name: '' });
const officeOptions = [{ value: '', label: 'Choose the office…' }, ...props.offices];
const submit = () => form.post('/admin/kiosks/codes', { preserveScroll: true, onSuccess: () => form.reset('name') });

// A terminal that has been used in the last ten minutes is shown as online.
const now = ref(Date.now());
let timer;
onMounted(() => (timer = window.setInterval(() => (now.value = Date.now()), 30000)));
onBeforeUnmount(() => clearInterval(timer));

const online = (terminal) => terminal.active && terminal.last_seen_at && now.value - new Date(terminal.last_seen_at).getTime() < 10 * 60 * 1000;
const active = computed(() => props.terminals.filter((t) => t.active));
const submitted = computed(() => active.value.reduce((sum, t) => sum + t.requests_submitted, 0));

const ago = (iso) => {
    if (!iso) return 'never';
    const minutes = Math.round((now.value - new Date(iso).getTime()) / 60000);
    if (minutes < 1) return 'just now';
    if (minutes < 60) return `${minutes} min ago`;
    if (minutes < 1440) return `${Math.round(minutes / 60)} h ago`;

    return `${Math.round(minutes / 1440)} d ago`;
};
const minutesLeft = (iso) => Math.max(0, Math.round((new Date(iso).getTime() - now.value) / 60000));

const copy = async (text) => {
    try {
        await navigator.clipboard.writeText(text);
        toast.success('Copied.');
    } catch {
        toast.info('Select the code and copy it.');
    }
};

const revoke = async (terminal) => {
    if (await confirm({ title: `Revoke ${terminal.name}?`, text: 'The kiosk stops working on its next request and must be set up again with a new code.', confirmText: 'Revoke', tone: 'danger' })) {
        router.post(`/admin/kiosks/${terminal.id}/revoke`, {}, { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="Kiosks" />

    <StaffLayout>
        <PageHeader
            title="Kiosks"
            description="The walk-in kiosks. Set up a new one with a one-time code; no password is ever typed on a public screen."
            :breadcrumbs="[{ label: 'Dashboard', href: '/dashboard' }, { label: 'Kiosks' }]"
        />

        <div class="mb-6 grid gap-4 sm:grid-cols-3">
            <StatCard label="Active kiosks" :value="active.length" icon="desktop" tone="primary" :note="`${terminals.length - active.length} revoked`" />
            <StatCard label="Online now" :value="active.filter(online).length" icon="activity" tone="success" note="used in the last 10 minutes" />
            <StatCard label="Requests submitted" :value="submitted" icon="clipboard" tone="info" note="through the active kiosks" />
        </div>

        <div class="grid gap-6 xl:grid-cols-3">
            <div class="space-y-6 xl:col-span-1">
                <ChartCard title="Set up a kiosk" :description="`Create a code, then enter it on the kiosk. A code works once and expires after ${pairingMinutes} minutes.`">
                    <form class="space-y-4" @submit.prevent="submit">
                        <FormGroup id="office" label="Office" required :error="form.errors.office_id">
                            <Select id="office" v-model="form.office_id" :options="officeOptions" required />
                        </FormGroup>
                        <FormGroup id="name" label="Name of this kiosk" required :error="form.errors.name" helper-text="For example “ESD counter 1”.">
                            <input id="name" v-model="form.name" type="text" maxlength="80" required class="block w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-sm text-text shadow-xs focus:border-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring" />
                        </FormGroup>
                        <Button type="submit" block :loading="form.processing"><Icon name="key" size="sm" />Create a set-up code</Button>
                    </form>

                    <div v-if="issued" class="chart-rise mt-5 overflow-hidden rounded-xl border border-success-border bg-success-bg/60 p-5 text-center" role="status">
                        <p class="text-xs font-semibold tracking-wide text-success uppercase">Code for {{ issued.name }}</p>
                        <p class="my-2 font-mono text-4xl font-semibold tracking-[0.2em] text-text select-all">{{ issued.code }}</p>
                        <p class="text-xs text-text-muted">Expires in about {{ minutesLeft(issued.expires_at) }} minutes. It is shown only now.</p>
                        <Button class="mt-3" variant="outline" size="sm" @click="copy(issued.code)"><Icon name="copy" size="sm" />Copy</Button>
                    </div>

                    <template #footer>
                        On the kiosk, open <a :href="`${kioskUrl}/setup`" target="_blank" rel="noopener" class="font-medium text-primary underline">{{ kioskUrl }}/setup</a> and enter the code.
                    </template>
                </ChartCard>

                <ChartCard v-if="pending.length" title="Waiting to be used" description="Codes that have not been entered yet.">
                    <ul class="divide-y divide-border text-sm">
                        <li v-for="code in pending" :key="code.id" class="flex items-center justify-between gap-3 py-2">
                            <span><span class="font-medium text-text">{{ code.name }}</span><span class="block text-xs text-text-muted">{{ code.office }}</span></span>
                            <Badge tone="warning">{{ minutesLeft(code.expires_at) }} min left</Badge>
                        </li>
                    </ul>
                </ChartCard>
            </div>

            <div class="xl:col-span-2">
                <ChartCard title="Paired kiosks" description="Every device that has been set up. Revoking one stops it at once.">
                    <EmptyState v-if="terminals.length === 0" icon="desktop" title="No kiosks yet" description="Create a set-up code, then enter it on the kiosk." />
                    <ul v-else class="grid gap-4 sm:grid-cols-2">
                        <li v-for="terminal in terminals" :key="terminal.id" class="rounded-xl border border-border bg-surface p-4" :class="terminal.active ? '' : 'opacity-60'">
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex min-w-0 items-center gap-3">
                                    <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-csc-blue text-white"><Icon name="desktop" size="sm" /></span>
                                    <div class="min-w-0">
                                        <p class="truncate font-semibold text-text">{{ terminal.name }}</p>
                                        <p class="truncate text-xs text-text-muted">{{ terminal.office }}<template v-if="terminal.files_at_esd"> · files under ESD</template></p>
                                    </div>
                                </div>
                                <span v-if="!terminal.active" class="rounded-full bg-danger-bg px-2.5 py-0.5 text-xs font-semibold text-danger">Revoked</span>
                                <span v-else-if="online(terminal)" class="inline-flex items-center gap-1.5 rounded-full bg-success-bg px-2.5 py-0.5 text-xs font-semibold text-success"><span class="size-1.5 rounded-full bg-success" />Online</span>
                                <span v-else class="rounded-full bg-surface-muted px-2.5 py-0.5 text-xs font-semibold text-text-muted">Idle</span>
                            </div>
                            <dl class="mt-3 grid grid-cols-2 gap-2 text-xs">
                                <div><dt class="text-text-muted">Last used</dt><dd class="font-medium text-text">{{ ago(terminal.last_seen_at) }}</dd></div>
                                <div><dt class="text-text-muted">Requests</dt><dd class="font-medium text-text">{{ terminal.requests_submitted }}</dd></div>
                            </dl>
                            <div v-if="terminal.active" class="mt-3 text-right">
                                <Button variant="ghost" size="sm" @click="revoke(terminal)"><Icon name="power" size="sm" />Revoke</Button>
                            </div>
                        </li>
                    </ul>
                </ChartCard>
            </div>
        </div>
    </StaffLayout>
</template>
