<script setup>
import { onMounted } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { useSplash } from '@/Composables/useSplash';

/*
 * Where IMIS sends staff. The "Opening ETTS" splash (TransitionSplash,
 * beside the page in app.js) is raised during setup, so it paints in the
 * same frame as the server-drawn copy in app.blade.php, which then goes.
 * The one-time token is posted back while it stays up: the server asks
 * IMIS who it was issued for and either signs the person in (IMIS has
 * already shown its own "Login Successful", so none is repeated here) or
 * sends them to /login with the reason.
 */
const props = defineProps({
    token: { type: String, required: true },
});

const { show, hide } = useSplash();

show({ instant: true, logo: 'app_logo', lead: 'Opening', title: 'ETTS', subtitle: 'Signing you in from IMIS' });

onMounted(() => {
    router.post('/sso', { sso_token: props.token }, {
        replace: true,
        onSuccess: () => hide(),
        onError: () => hide(),
    });
});
</script>

<template>
    <Head title="Signing you in" />
</template>
