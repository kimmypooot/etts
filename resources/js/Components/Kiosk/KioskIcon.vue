<script setup>
/**
 * The kiosk's own icon set: simple 24px outline glyphs, drawn to read at a
 * distance on a touch screen. Decorative by default (aria-hidden); the label
 * next to an icon carries the meaning.
 */
defineProps({
    name: { type: String, required: true },
    size: { type: Number, default: 24 },
    stroke: { type: Number, default: 1.8 },
});

const paths = {
    certificate: ['M7 3h8l4 4v14H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z', 'M15 3v4h4', 'M9 12h6', 'M9 16h4'],
    authenticate: ['M12 3 4 6v5c0 5 3.4 8.4 8 10 4.6-1.6 8-5 8-10V6l-8-3Z', 'm8.5 12 2.4 2.4 4.6-4.8'],
    both: ['M7 3h7l4 4v8', 'M14 3v4h4', 'M5 7v12a2 2 0 0 0 2 2h6', 'M17 21c2.2-.8 4-2.6 4-5v-2l-4-1.5L13 14v2c0 2.4 1.8 4.2 4 5Z', 'm15.3 16.3 1.2 1.2 2.2-2.3'],
    graduate: ['M2 9.5 12 5l10 4.5L12 14 2 9.5Z', 'M6 11.6V16c0 1.4 2.7 3 6 3s6-1.6 6-3v-4.4', 'M22 9.5V15'],
    arrowRight: ['M5 12h14', 'm13 6 6 6-6 6'],
    arrowLeft: ['M19 12H5', 'm11 18-6-6 6-6'],
    check: ['m5 12.5 4.5 4.5L19 7.5'],
    user: ['M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z', 'M4 21a8 8 0 0 1 16 0'],
    clock: ['M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z', 'M12 7v5l3 2'],
    upload: ['M12 16V4', 'm7 9 5-5 5 5', 'M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3'],
    lock: ['M6 11h12v9H6z', 'M8 11V8a4 4 0 0 1 8 0v3'],
    alert: ['M12 3 2 20h20L12 3Z', 'M12 10v4', 'M12 17.2v.1'],
    edit: ['M4 20h4L19 9l-4-4L4 16v4Z', 'm13.5 6.5 4 4'],
    close: ['M6 6l12 12', 'M18 6 6 18'],
    pencil: ['M4 20h4L19 9l-4-4L4 16v4Z'],
    desktop: ['M3 5h18v11H3z', 'M8 20h8', 'M12 16v4'],
    key: ['M15 10a4 4 0 1 1-3.6-4', 'M12 10 4 18v3h3l1-1v-2h2l1-1v-2l2-2', 'M17 7h.01'],
    wifi: ['M5 12.5a10 10 0 0 1 14 0', 'M8.5 16a5 5 0 0 1 7 0', 'M12 19.5h.01'],
    shield: ['M12 3 4 6v5c0 5 3.4 8.4 8 10 4.6-1.6 8-5 8-10V6l-8-3Z'],
    sparkle: ['M12 3v4', 'M12 17v4', 'M3 12h4', 'M17 12h4', 'm6 6 2.5 2.5', 'm15.5 15.5 2.5 2.5', 'm18 6-2.5 2.5', 'm8.5 15.5-2.5 2.5'],
};
</script>

<template>
    <svg :width="size" :height="size" viewBox="0 0 24 24" fill="none" stroke="currentColor" :stroke-width="stroke" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path v-for="d in paths[name] ?? []" :key="d" :d="d" />
    </svg>
</template>
