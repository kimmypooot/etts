import { computed } from 'vue';

/**
 * The images the IMIS components ask for (TransitionSplash: `logo`). IMIS
 * lets the office replace them (Admin → Branding); ETTS uses the CSC
 * seal served from public/images.
 */
const builtIn = {
    logo: '/images/csc-logo.png',
    // The IMIS logo, on the "Opening <system>" card (public/images/imis-logo.png).
    app_logo: '/images/imis-logo.png',
};

export function useBranding() {
    return computed(() => builtIn);
}
