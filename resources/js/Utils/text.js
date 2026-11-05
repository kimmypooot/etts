// Short words kept lowercase inside a title (articles, conjunctions,
// short prepositions), as in "Returned for Correction" or "Head of Office".
const MINOR_WORDS = new Set(['a', 'an', 'and', 'as', 'at', 'but', 'by', 'for', 'in', 'nor', 'of', 'on', 'or', 'per', 'the', 'to', 'via', 'vs']);

/**
 * Title Case for statuses and other short labels: every word capitalized
 * except minor words that aren't first or last. Words already holding a
 * capital (acronyms such as HR, PDS or IPCR, or names) are left as they are.
 * Hyphenated words are capitalized on each part, minor words aside
 * ("Face-to-Face", "Self-Paced").
 */
export function titleCase(value) {
    if (!value) return value;

    const words = String(value).trim().split(/\s+/);

    return words
        .map((word, index) => {
            if (/[A-Z]/.test(word.slice(1)) || /^[A-Z]{2,}/.test(word)) return word;

            const lower = word.toLowerCase();
            const edge = index === 0 || index === words.length - 1;
            if (!edge && MINOR_WORDS.has(lower)) return lower;

            return lower
                .split('-')
                .map((part, at) => (at > 0 && MINOR_WORDS.has(part) ? part : part.charAt(0).toUpperCase() + part.slice(1)))
                .join('-');
        })
        .join(' ');
}

// Lower-case particles inside a name ("Juan dela Cruz", "Maria y Santos").
const NAME_PARTICLES = new Set(['de', 'del', 'dela', 'delas', 'delos', 'la', 'las', 'los', 'van', 'von', 'y']);
// Generational suffixes written in capitals. A lone "I" or "V" is far more
// likely a middle initial, so those are left to the normal rule.
const ROMAN_SUFFIX = /^(ii|iii|iv|vi|vii|viii|ix)$/;

/**
 * A person's name as stored is often shouted, whispered or mixed
 * ("ASHTON lEIGH aMOR", "peralta, ma. grace sol alma n/a"). This is for
 * showing it: every word capitalised, particles ("dela") and generational
 * suffixes ("III") handled, "O'Brien" / "Mary-Ann" / "J.R." capitalised on
 * each part, "Mc" kept, and a stray "n/a" placeholder dropped. The stored
 * value is never changed.
 */
export function personName(value) {
    if (!value) return value ?? '';

    const words = String(value)
        .trim()
        .split(/\s+/)
        .filter((word) => word.replace(/,$/, '').toLowerCase() !== 'n/a');

    const result = words.map((word, index) => {
        const comma = word.endsWith(',') ? ',' : '';
        const bare = comma ? word.slice(0, -1) : word;
        const lower = bare.toLowerCase();

        if (ROMAN_SUFFIX.test(lower)) return lower.toUpperCase() + comma;
        if (index > 0 && NAME_PARTICLES.has(lower)) return lower + comma;

        const cased = lower
            .split(/([-'.])/)
            .map((part) => (/^[-'.]$/.test(part) ? part : part.charAt(0).toUpperCase() + part.slice(1)))
            .join('');

        return cased.replace(/^Mc([a-z])/, (_, letter) => 'Mc' + letter.toUpperCase()) + comma;
    });

    return result.join(' ').replace(/,$/, '').trim();
}
