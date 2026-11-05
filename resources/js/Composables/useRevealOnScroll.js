import { onMounted, onBeforeUnmount, ref } from 'vue';

/**
 * A lightweight stand-in for cscro8.com's AOS fade-in-on-scroll, without
 * pulling in the AOS library: the element starts faded/offset and settles
 * into place the first time it crosses into the viewport. Respects
 * prefers-reduced-motion by doing nothing (the element is simply visible).
 */
export function useRevealOnScroll() {
    const target = ref(null);
    const revealed = ref(window.matchMedia('(prefers-reduced-motion: reduce)').matches);

    let observer;

    onMounted(() => {
        if (revealed.value || !target.value) {
            revealed.value = true;

            return;
        }

        observer = new IntersectionObserver(
            ([entry]) => {
                if (entry.isIntersecting) {
                    revealed.value = true;
                    observer.disconnect();
                }
            },
            { threshold: 0.15 },
        );

        observer.observe(target.value);
    });

    onBeforeUnmount(() => observer?.disconnect());

    return { target, revealed };
}
