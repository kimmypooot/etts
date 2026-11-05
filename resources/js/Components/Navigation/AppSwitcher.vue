<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import Icon from '../UI/Icon.vue';

/**
 * The header's "Examination-Related Info. System" switcher (the legacy grid
 * button): a panel of the sister ERIS systems ESD staff move between, plus a
 * way back to the IMIS portal. The links come from the server
 * (config/etts.php) and the switcher is for ESD only.
 */
const page = usePage();
const open = ref(false);
const rootRef = ref(null);
const buttonRef = ref(null);

const onDocumentClick = (event) => {
    if (open.value && rootRef.value && !rootRef.value.contains(event.target)) open.value = false;
};

const onKeydown = (event) => {
    if (event.key !== 'Escape' || !open.value) return;

    open.value = false;
    buttonRef.value?.focus();
};

onMounted(() => {
    document.addEventListener('click', onDocumentClick);
    document.addEventListener('keydown', onKeydown);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', onDocumentClick);
    document.removeEventListener('keydown', onKeydown);
});
</script>

<template>
    <div v-if="page.props.relatedSystems?.length" ref="rootRef" class="relative">
        <button
            ref="buttonRef"
            type="button"
            class="flex size-9 items-center justify-center rounded-full text-text hover:bg-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
            :class="open ? 'bg-surface-muted' : ''"
            aria-label="Open application switcher"
            aria-haspopup="true"
            :aria-expanded="open"
            aria-controls="app-switcher-panel"
            @click="open = !open"
        >
            <Icon name="grid" size="md" />
        </button>

        <div
            v-if="open"
            id="app-switcher-panel"
            class="absolute right-0 z-50 mt-2 w-72 overflow-hidden rounded-xl border border-border bg-surface shadow-lg sm:w-80"
        >
            <h2 class="border-b border-border bg-surface-muted px-4 py-3 text-xs font-semibold tracking-wide text-text-muted uppercase">
                Examination-Related Info. System
            </h2>

            <ul class="grid grid-cols-3 gap-1 p-3">
                <li v-for="system in page.props.relatedSystems" :key="system.label">
                    <a
                        :href="system.url"
                        class="flex flex-col items-center gap-2 rounded-lg p-3 text-center hover:bg-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                        @click="open = false"
                    >
                        <span class="flex size-10 items-center justify-center rounded-lg bg-primary/10 text-primary">
                            <Icon :name="system.icon" size="md" />
                        </span>
                        <span class="text-xs leading-tight font-semibold text-text">{{ system.label }}</span>
                    </a>
                </li>
            </ul>

            <a
                v-if="page.props.imisUrl"
                :href="page.props.imisUrl"
                class="block border-t border-border bg-surface-muted px-4 py-3 text-center text-xs font-semibold tracking-wide text-primary uppercase hover:bg-border/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                @click="open = false"
            >
                Main Portal →
            </a>
        </div>
    </div>
</template>
