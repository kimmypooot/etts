<?php

namespace App\Models;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Scopes\OfficeScope;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A client request to ESD. `id` is the legacy record id.
 *
 * Office scoping is applied in SQL by {@see OfficeScope}: field office staff
 * only ever see their own office. Anything that must span offices on purpose
 * (the SPELS duplicate rule) queries the table directly and says so.
 */
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    protected $guarded = [];

    public const CREATED_AT = null;

    protected static function booted(): void
    {
        static::addGlobalScope(new OfficeScope);
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'transaction_type' => TransactionType::class,
            'transaction_status' => TransactionStatus::class,
            'transaction_date' => 'datetime',
            'or_date' => 'date',
            'date_sent_to_ro' => 'date',
            'date_received_ro' => 'date',
            'date_completed' => 'date',
            'doc_release_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Office, $this>
     */
    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /**
     * @return HasOne<IssuanceRequest, $this>
     */
    public function issuanceRequest(): HasOne
    {
        return $this->hasOne(IssuanceRequest::class);
    }
}
