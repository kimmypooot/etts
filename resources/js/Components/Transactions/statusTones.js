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
