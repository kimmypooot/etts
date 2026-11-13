// Shared option lists and input styling for the processing steps
// (the same values the server validates in ProcessSteps).
export const sexOptions = [{ value: '', label: '—' }, { value: 'Male', label: 'Male' }, { value: 'Female', label: 'Female' }];
export const civilOptions = ['', 'Single', 'Married', 'Widowed', 'Separated', 'Annulled'].map((value) => ({ value, label: value || '—' }));
export const employmentOptions = ['', 'Employed', 'Unemployed', 'Self-Employed', 'Student', 'Retired'].map((value) => ({ value, label: value || '—' }));

export const fieldClass =
    'block w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-sm text-text shadow-xs focus:border-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring disabled:bg-surface-muted disabled:text-text-muted';
