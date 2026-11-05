// Dates arrive as Y-m-d strings; show them the way PALD writes them.
export const formatDate = (iso) =>
    iso
        ? new Date(`${iso}T00:00:00`).toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' })
        : '';

export const formatNumber = (value) => Number(value ?? 0).toLocaleString('en-PH');

export const formatSize = (bytes) => {
    if (bytes == null) {
        return '';
    }

    return bytes >= 1024 * 1024 ? `${(bytes / (1024 * 1024)).toFixed(1)} MB` : `${Math.round(bytes / 1024)} KB`;
};
