<script setup>
/**
 * The PDF file mark: a red page with a folded corner and "PDF" on it. Not in
 * Icon.vue because that set is single-colour outline paths.
 */
defineProps({
    size: { type: Number, default: 20 },
});
</script>

<template>
    <svg :width="size" :height="size" viewBox="0 0 24 24" fill="none" aria-hidden="true" class="shrink-0">
        <path d="M5 2.75A1.75 1.75 0 0 1 6.75 1h7.5L20 6.75v14.5A1.75 1.75 0 0 1 18.25 23H6.75A1.75 1.75 0 0 1 5 21.25V2.75Z" fill="#E5252A" />
        <path d="M14.25 1v4.25c0 .83.67 1.5 1.5 1.5H20L14.25 1Z" fill="#9B1C1F" />
        <text x="12.5" y="18.5" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-size="6.2" font-weight="700" fill="#fff">PDF</text>
    </svg>
</template>
