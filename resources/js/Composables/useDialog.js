import { reactive, readonly } from 'vue';

/**
 * Promise-based alerts, confirmations, and toasts — the legacy IMIS's
 * SweetAlert2 dialogs ("Are you sure?", "Saved!", "Deleted!") rebuilt
 * without the dependency. Rendered once by DialogHost at the app root.
 *
 *   if (await confirm({ title: 'Deactivate user?', text: '…', tone: 'danger' })) { … }
 *   toast.success('Saved!')
 */
const state = reactive({
    dialog: null,
    toasts: [],
});

let nextToastId = 1;

const open = (options, withCancel) =>
    new Promise((resolve) => {
        state.dialog = {
            icon: options.icon ?? (withCancel ? 'warning' : 'info'),
            title: options.title,
            text: options.text ?? '',
            confirmText: options.confirmText ?? (withCancel ? 'Confirm' : 'OK'),
            cancelText: options.cancelText ?? 'Cancel',
            tone: options.tone ?? 'primary',
            showCancel: withCancel,
            resolve,
        };
    });

/**
 * Asks a yes/no question. Resolves true on confirm, false on cancel,
 * Escape, or backdrop click.
 */
export const confirm = (options) => open(options, true);

/**
 * A one-button message (e.g. an error the user must acknowledge).
 */
export const alert = (options) => open(options, false);

const settle = (result) => {
    const dialog = state.dialog;
    state.dialog = null;
    dialog?.resolve(result);
};

const push = (type, message, timeout) => {
    const id = nextToastId++;
    state.toasts.push({ id, type, message });
    if (timeout) window.setTimeout(() => dismissToast(id), timeout);
};

export const dismissToast = (id) => {
    state.toasts = state.toasts.filter((item) => item.id !== id);
};

export const toast = {
    success: (message) => push('success', message, 3000),
    info: (message) => push('info', message, 4000),
    // Errors stay until dismissed, so they can't be missed.
    error: (message) => push('error', message, null),
};

export function useDialog() {
    return { state: readonly(state), confirm, alert, toast, settle, dismissToast };
}
