<script setup>
import { computed } from 'vue';

/**
 * A user's profile photo, or their initials on navy when there is none.
 * `highlight` adds the legacy IMIS header ring: a light border that turns
 * brand blue when the surrounding `group` is hovered.
 */
const props = defineProps({
    name: { type: String, required: true },
    photoUrl: { type: String, default: null },
    size: { type: String, default: 'md', validator: (value) => ['sm', 'md', 'lg', 'xl'].includes(value) },
    highlight: { type: Boolean, default: false },
});

const ring = computed(() => (props.highlight ? 'border-2 border-border transition-colors group-hover:border-primary' : ''));

const sizes = { sm: 'h-7 w-7 text-[0.65rem]', md: 'h-9 w-9 text-xs', lg: 'h-14 w-14 text-base', xl: 'h-24 w-24 text-2xl' };

const initials = computed(() =>
    props.name
        .split(/\s+/)
        .filter((part) => part && part.length > 1 && !part.endsWith('.'))
        .map((part) => part[0])
        .filter((_, index, all) => index === 0 || index === all.length - 1)
        .join('')
        .toUpperCase(),
);
</script>

<template>
    <img v-if="photoUrl" :src="photoUrl" alt="" class="shrink-0 rounded-full object-cover" :class="[sizes[size], ring || 'ring-2 ring-surface']" />
    <span v-else class="flex shrink-0 items-center justify-center rounded-full bg-primary font-semibold text-white" :class="[sizes[size], ring]" aria-hidden="true">
        {{ initials || '?' }}
    </span>
</template>
