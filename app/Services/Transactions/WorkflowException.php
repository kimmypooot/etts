<?php

namespace App\Services\Transactions;

use RuntimeException;

/**
 * A business-rule refusal whose message is safe to show to the person who
 * asked (a wrong status flow, a missing reason, a duplicate approval). Never
 * used for faults; those stay ordinary exceptions.
 */
class WorkflowException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $reason = null)
    {
        parent::__construct($message);
    }
}
