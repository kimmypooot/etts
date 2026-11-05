<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import Icon from '../UI/Icon.vue';
import { toast, useDialog } from '../../Composables/useDialog';

/**
 * Renders useDialog()'s alert/confirm dialog and toast stack (the legacy
 * SweetAlert look: centered card, big status icon, navy confirm / red
 * cancel). Also turns the server's one-off flash messages into toasts, so
 * every "Saved." from a controller surfaces the same way.
 */
const { state, settle, dismissToast } = useDialog();

const confirmRef = ref(null);
let returnFocus = null;

const icons = {
    warning: { name: 'exclamationMark', ring: 'border-warning-border text-warning bg-warning-bg' },
    success: { name: 'check', ring: 'border-success-border text-success bg-success-bg' },
    error: { name: 'close', ring: 'border-danger-border text-danger bg-danger-bg' },
    info: { name: 'infoMark', ring: 'border-info-border text-info bg-info-bg' },
    question: { name: 'questionMark', ring: 'border-info-border text-info bg-info-bg' },
};

const confirmTone = { primary: 'bg-primary hover:bg-primary-hover', danger: 'bg-danger hover:bg-accent-hover', success: 'bg-success hover:opacity-90' };

watch(
    () => state.dialog,
    async (dialog) => {
        if (dialog) {
            returnFocus = document.activeElement;
            await nextTick();
            confirmRef.value?.focus();
        } else {
            returnFocus?.focus?.();
        }
    },
);

const onKeydown = (event) => {
    if (event.key === 'Escape' && state.dialog) {
        event.stopPropagation();
        settle(false);
    }
};

// Server flash messages → toasts.
let removeListener;
onMounted(() => {
    document.addEventListener('keydown', onKeydown, true);
    removeListener = router.on('success', (event) => {
        // Sign-in pages show their own status message inline.
        if (event.detail.page.component.startsWith('Auth/')) return;

        const flash = event.detail.page.props.flash ?? {};
        if (flash.status) toast.success(flash.status);
        if (flash.error) toast.error(flash.error);
    });
});

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeydown, true);
    removeListener?.();
});

const toastStyles = {
    success: { icon: 'checkCircle', classes: 'border-success-border text-success' },
    info: { icon: 'info', classes: 'border-info-border text-info' },
    error: { icon: 'xCircle', classes: 'border-danger-border text-danger' },
};
</script>

<template>
    <Teleport to="body">
        <!-- Alert / confirm -->
        <Transition enter-active-class="transition duration-150" enter-from-class="opacity-0 scale-95" leave-active-class="transition duration-100" leave-to-class="opacity-0">
            <div v-if="state.dialog" class="fixed inset-0 z-[90] flex items-center justify-center p-4">
                <div class="fixed inset-0 bg-text/50" aria-hidden="true" @click="settle(false)" />
                <div
                    role="alertdialog"
                    aria-modal="true"
                    aria-labelledby="dialog-title"
                    :aria-describedby="state.dialog.text ? 'dialog-text' : undefined"
                    class="relative w-full max-w-sm rounded-lg bg-surface p-6 text-center shadow-xl"
                >
                    <span
                        class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full border-4"
                        :class="icons[state.dialog.icon]?.ring ?? icons.info.ring"
                        aria-hidden="true"
                    >
                        <Icon :name="icons[state.dialog.icon]?.name ?? icons.info.name" size="xl" />
                    </span>
                    <h2 id="dialog-title" class="text-lg font-semibold text-text">{{ state.dialog.title }}</h2>
                    <p v-if="state.dialog.text" id="dialog-text" class="mt-2 text-sm whitespace-pre-line text-text-muted">{{ state.dialog.text }}</p>

                    <div class="mt-6 flex justify-center gap-3">
                        <button
                            ref="confirmRef"
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-md px-4 py-2 text-sm font-medium text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:ring-offset-2"
                            :class="confirmTone[state.dialog.tone] ?? confirmTone.primary"
                            @click="settle(true)"
                        >
                            <Icon name="check" size="sm" />
                            {{ state.dialog.confirmText }}
                        </button>
                        <button
                            v-if="state.dialog.showCancel"
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-md bg-danger px-4 py-2 text-sm font-medium text-white hover:bg-danger-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:ring-offset-2"
                            @click="settle(false)"
                        >
                            <Icon name="close" size="sm" />
                            {{ state.dialog.cancelText }}
                        </button>
                    </div>
                </div>
            </div>
        </Transition>

        <!-- Toasts -->
        <div class="pointer-events-none fixed top-20 right-4 z-[95] flex w-80 max-w-[calc(100vw-2rem)] flex-col gap-2" role="status" aria-live="polite">
            <TransitionGroup enter-active-class="transition duration-200" enter-from-class="translate-x-4 opacity-0" leave-active-class="transition duration-150" leave-to-class="opacity-0">
                <div
                    v-for="item in state.toasts"
                    :key="item.id"
                    class="pointer-events-auto flex items-start gap-3 rounded-lg border-l-4 bg-surface px-4 py-3 text-sm text-text shadow-lg"
                    :class="toastStyles[item.type].classes"
                >
                    <Icon :name="toastStyles[item.type].icon" />
                    <p class="flex-1 text-text">{{ item.message }}</p>
                    <button
                        type="button"
                        class="shrink-0 rounded p-0.5 text-text-muted hover:text-text focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                        aria-label="Dismiss notification"
                        @click="dismissToast(item.id)"
                    >
                        <Icon name="close" size="sm" />
                    </button>
                </div>
            </TransitionGroup>
        </div>
    </Teleport>
</template>
