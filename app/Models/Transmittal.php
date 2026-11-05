<?php

namespace App\Models;

use App\Enums\TransmittalDirection;
use App\Enums\TransmittalStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

/**
 * A batch of documents sent between ESD and a field office, in either direction.
 * Field office staff see only the batches addressed to or sent by their own office.
 */
class Transmittal extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected static function booted(): void
    {
        static::addGlobalScope('office', function (Builder $builder) {
            $user = Auth::user();

            if ($user === null || $user->isAdmin()) {
                return;
            }

            $office = $user->office_id ?? 0;
            $builder->where(fn (Builder $inner) => $inner->where('target_office_id', $office)->orWhere('origin_office_id', $office));
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'direction' => TransmittalDirection::class,
            'status' => TransmittalStatus::class,
            'created_at' => 'datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === TransmittalStatus::Draft;
    }

    /**
     * @return HasMany<TransmittalItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(TransmittalItem::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<Office, $this>
     */
    public function targetOffice(): BelongsTo
    {
        return $this->belongsTo(Office::class, 'target_office_id');
    }

    /**
     * @return BelongsTo<Office, $this>
     */
    public function originOffice(): BelongsTo
    {
        return $this->belongsTo(Office::class, 'origin_office_id');
    }

    /** The field office this batch belongs to: where it goes, or where it came from. */
    public function fieldOfficeId(): int
    {
        return $this->direction === TransmittalDirection::FoToRo ? (int) $this->origin_office_id : (int) $this->target_office_id;
    }
}
