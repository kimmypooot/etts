/**
 * Which semantic tone each transaction status takes, and how its word is
 * written. One place for the status pill and the board's status chips, so a
 * status never looks different in two spots. The strings are the ones in
 * TransactionStatus.php.
 */
export const statusTones = {
    submitted: 'neutral',
    forwarded: 'info',
    received: 'info',
    Completed: 'warning',
    Released: 'success',
    Approved: 'success',
    'Approved/Released': 'success',
    Disapproved: 'danger',
    Returned: 'warning',
    Cancelled: 'danger',
};

export const statusLabel = (status) => status.charAt(0).toUpperCase() + status.slice(1);

/** The legacy board's solid fill per status (white text); see `--color-st-*` in app.css. */
export const statusFills = {
    submitted: 'bg-st-submitted',
    forwarded: 'bg-st-forwarded',
    received: 'bg-st-received',
    Completed: 'bg-st-completed',
    Approved: 'bg-st-approved',
    'Approved/Released': 'bg-st-approved',
    Released: 'bg-st-released',
    Returned: 'bg-st-returned',
    Disapproved: 'bg-st-disapproved',
    Cancelled: 'bg-st-cancelled',
};
