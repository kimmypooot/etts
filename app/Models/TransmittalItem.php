<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of a transmittal: a transaction, or a manually described document.
 * `transaction_id` has no foreign key (see the migration), so the transaction
 * may since have moved to the deleted-records archive.
 */
class TransmittalItem extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_manual' => 'boolean'];
    }

    /**
     * @return BelongsTo<Transmittal, $this>
     */
    public function transmittal(): BelongsTo
    {
        return $this->belongsTo(Transmittal::class);
    }
}
