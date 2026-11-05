<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The extra details of a certification or authentication request. `id` is the
 * legacy `issuance_id`. The attachment columns hold file names on the private
 * `attachments` disk.
 */
class IssuanceRequest extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'date_of_exam_issuance' => 'date',
            'effectivity_date' => 'date',
            'release_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
