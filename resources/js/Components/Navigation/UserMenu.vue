<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import Icon from '../UI/Icon.vue';
import UserAvatar from '../UI/UserAvatar.vue';
import ConfirmDialog from '../Feedback/ConfirmDialog.vue';
import { firstName, useSplash } from '../../Composables/useSplash';

/**
 * The account menu, after IMIS's (Components/Navigation/UserMenu.vue):
 * who you are and your ETTS role, a way back to IMIS (where accounts
 * and roles are managed), the public page, and Sign out (confirmed).
 */
const props = defineProps({
    user: {
        type: Object,
        required: true,
        /** { name, username, email, photo_url, role_label } */
    },
});

const page = usePage();
const { show: showSplash, hide: hideSplash } = useSplash();
const open = ref(false);
const rootRef = ref(null);
const buttonRef = ref(null);

const close = () => {
    open.value = false;
};

const onDocumentClick = (event) => {
    if (open.value && rootRef.value && !rootRef.value.contains(event.target)) {
        close();
    }
};

const onKeydown = (event) => {
    if (event.key !== 'Escape' || !open.value) return;

    const hadFocus = rootRef.value?.contains(document.activeElement);
    close();
    if (hadFocus) buttonRef.value?.focus();
};

onMounted(() => {
    document.addEventListener('click', onDocumentClick);
    document.addEventListener('keydown', onKeydown);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', onDocumentClick);
    document.removeEventListener('keydown', onKeydown);
});

const confirmingLogout = ref(false);

const requestLogout = () => {
    close();
    confirmingLogout.value = true;
};

// As in IMIS: the "Signing Out" splash, a short hold so it can be read, then
// it stays up until the sign-in page has rendered underneath it.
const logout = () => {
    confirmingLogout.value = false;
    showSplash({
        title: 'Signing Out',
        subtitle: 'See you soon, {name}',
        name: firstName(props.user.name),
        note: 'Redirecting to the sign-in page…',
    });

    window.setTimeout(() => {
        router.post('/logout', {}, { onFinish: () => hideSplash(500) });
    }, 700);
};

const itemClass =
    'flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-text hover:bg-surface-muted focus:bg-surface-muted focus:outline-none';
const groupClass = 'border-b border-border py-1';
</script>

<template>
    <div ref="rootRef" class="relative">
        <button
            ref="buttonRef"
            type="button"
            class="group flex items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
            :aria-label="`Account menu for ${user.name}`"
            :aria-expanded="open"
            aria-controls="user-menu-panel"
            @click="open = !open"
        >
            <UserAvatar :name="user.name" :photo-url="user.photo_url" highlight />
            <span class="hidden flex-col whitespace-nowrap text-left leading-tight sm:flex">
                <span class="font-semibold text-text">{{ user.name }}</span>
                <span class="text-xs text-text-muted">{{ user.role_label }}</span>
            </span>
            <Icon name="chevronDown" size="sm" class="shrink-0 text-text-muted" />
        </button>

        <div
            v-if="open"
            id="user-menu-panel"
            class="absolute right-0 z-50 mt-2 w-64 rounded-md border border-border bg-surface py-1 shadow-lg"
        >
            <div class="border-b border-border px-3 py-2">
                <p class="text-sm font-semibold break-words text-text">{{ user.name }}</p>
                <p class="text-xs text-text-muted">ETTS {{ user.role_label }}</p>
                <p class="text-xs break-all text-text-muted">@{{ user.username }}<template v-if="user.email"> · {{ user.email }}</template></p>
            </div>

            <div role="group" aria-label="Elsewhere" :class="groupClass">
                <a v-if="page.props.imisUrl" :href="page.props.imisUrl" :class="itemClass" @click="close">
                    <Icon name="home" size="sm" />
                    Back to IMIS
                </a>
            </div>

            <div class="pt-1">
                <button type="button" :class="itemClass" @click="requestLogout">
                    <Icon name="logout" size="sm" />
                    Sign out
                </button>
            </div>
        </div>

        <ConfirmDialog
            :open="confirmingLogout"
            title="Sign out of ETTS?"
            :danger="false"
            confirm-label="Sign out"
            @confirm="logout"
            @cancel="confirmingLogout = false"
        >
            To come back, open ETTS from IMIS.
        </ConfirmDialog>
    </div>
</template>
