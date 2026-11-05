import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Notices a screen nobody is using. After `warnAfter` seconds without a touch,
 * click or key press it reports a warning (with the seconds left); after
 * `timeout` seconds it calls `onTimeout`. Any activity clears the warning. A
 * shared screen must not keep someone's answers on show, so the kiosk uses this
 * to throw a half-filled form away.
 */
export function useIdle({ timeout, warnBefore = 20, onTimeout }) {
    const warning = ref(false);
    const remaining = ref(warnBefore);
    let last = Date.now();
    let timer = null;

    const events = ['pointerdown', 'pointermove', 'keydown', 'touchstart', 'wheel'];

    const touch = () => {
        // The warning is dismissed by an explicit tap, not by the pointer drifting.
        if (!warning.value) last = Date.now();
    };

    const stillHere = () => {
        last = Date.now();
        warning.value = false;
    };

    const tick = () => {
        const idle = (Date.now() - last) / 1000;
        remaining.value = Math.max(0, Math.ceil(timeout - idle));
        warning.value = idle >= timeout - warnBefore;

        if (idle >= timeout) {
            clearInterval(timer);
            onTimeout();
        }
    };

    onMounted(() => {
        events.forEach((name) => window.addEventListener(name, touch, { passive: true }));
        timer = window.setInterval(tick, 1000);
    });

    onBeforeUnmount(() => {
        events.forEach((name) => window.removeEventListener(name, touch));
        clearInterval(timer);
    });

    return { warning, remaining, stillHere };
}
