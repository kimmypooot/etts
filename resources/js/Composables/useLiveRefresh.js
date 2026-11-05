import { onBeforeUnmount, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * Keeps a board current without a manual reload (replaces the legacy
 * `check_updates` poll). Every `interval` ms, while the tab is visible, asks the
 * server for a cheap signature of the records the user may see; when it differs
 * from the last one seen, reloads just the named props. Our own actions also
 * change the signature, so it is re-learned after every visit rather than
 * treated as someone else's change.
 */
export function useLiveRefresh(url, only, interval = 30000) {
    let signature = null;
    let timer = null;
    let stopListening = () => {};

    const check = async () => {
        if (document.hidden) return;

        try {
            const response = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
            if (!response.ok) return;

            const next = (await response.json()).signature;

            if (signature !== null && next !== signature) {
                router.reload({ only, preserveScroll: true, preserveState: true });
            }

            signature = next;
        } catch {
            // Offline or mid-deploy: try again at the next tick.
        }
    };

    onMounted(() => {
        check();
        timer = setInterval(check, interval);
        document.addEventListener('visibilitychange', check);
        stopListening = router.on('success', () => (signature = null));
    });

    onBeforeUnmount(() => {
        clearInterval(timer);
        document.removeEventListener('visibilitychange', check);
        stopListening();
    });
}
