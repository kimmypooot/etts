<?php

namespace App\Models;

use App\Enums\DeletionReason;
use Illuminate\Database\Eloquent\Model;

/**
 * A deleted transaction, kept in the archive instead of being destroyed. `id`
 * is the id the transaction had while live.
 */
class DeletedTransaction extends Model
{
    protected $guarded = [];

    public $incrementing = false;

    public const CREATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'transaction_date' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /** The operator-facing reason, or null for a code outside the vocabulary. */
    public function reason(): ?DeletionReason
    {
        return DeletionReason::tryFrom((string) $this->deletion_reason_code);
    }
}
