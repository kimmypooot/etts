import { ref } from 'vue';

/**
 * Green "just updated" flash for table rows (ported from the legacy board's
 * .row-just-updated). Call flash(ids) from an Inertia onSuccess; bind
 * `{ 'row-just-updated': isFlashing(row.id) }` on the <tr>.
 */
export function useRowFlash(duration = 1600) {
    const flashing = ref([]);

    const flash = (ids) => {
        const list = [].concat(ids);
        flashing.value = [...flashing.value, ...list];
        setTimeout(() => {
            flashing.value = flashing.value.filter((id) => !list.includes(id));
        }, duration);
    };

    const isFlashing = (id) => flashing.value.includes(id);

    return { flash, isFlashing };
}
