/**
 * Colours and number formats shared by every chart, so the charts agree with
 * each other and with the rest of the app. The values are CSS variables (set in
 * resources/css/app.css), not hex codes, so a brand change reaches the charts.
 */
export const seriesColors = [
    'var(--color-chart-1)',
    'var(--color-chart-2)',
    'var(--color-chart-3)',
    'var(--color-chart-4)',
    'var(--color-chart-5)',
    'var(--color-chart-6)',
];

export const toneColors = {
    primary: 'var(--color-csc-blue)',
    info: 'var(--color-csc-blue-light)',
    success: 'var(--color-success)',
    warning: 'var(--color-chart-5)',
    danger: 'var(--color-csc-red)',
    neutral: 'var(--color-chart-neutral)',
};

/** Soft tinted background and strong foreground for each tone, for chips and icon bubbles. */
export const toneChips = {
    primary: 'bg-info-bg text-primary',
    info: 'bg-info-bg text-csc-blue-light',
    success: 'bg-success-bg text-success',
    warning: 'bg-warning-bg text-warning',
    danger: 'bg-danger-bg text-danger',
    neutral: 'bg-surface-muted text-text-muted',
};

export const formatNumber = (value) => Number(value ?? 0).toLocaleString('en-PH');

export const formatPeso = (value) => `₱${Number(value ?? 0).toLocaleString('en-PH')}`;

export const formatCompact = (value) =>
    Number(value ?? 0).toLocaleString('en-PH', { notation: 'compact', maximumFractionDigits: 1 });

/** The smallest "nice" axis maximum at or above the value (10, 20, 50, 100, 200, ...). */
export const niceMax = (value) => {
    if (value <= 0) return 4;
    const power = 10 ** Math.floor(Math.log10(value));
    const step = [1, 2, 2.5, 5, 10].find((candidate) => candidate * power >= value) ?? 10;

    return step * power;
};

export const prefersReducedMotion = () =>
    typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
