<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A paired kiosk device. The token the device holds is never stored, only its hash.
 */
class KioskTerminal extends Model
{
    protected $guarded = [];

    protected $hidden = ['token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'paired_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /**
     * @return BelongsTo<Office, $this>
     */
    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function pairedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paired_by');
    }

    /**
     * The office this terminal files requests under. A front desk such as PACD
     * files under ESD, whatever office the terminal itself is paired to.
     */
    public function filingOfficeId(): int
    {
        $office = $this->office;

        if ($office !== null && in_array(strtoupper($office->code), config('etts.kiosk.files_at_esd'), true)) {
            return (int) (Office::where('code', 'ESD')->value('id') ?? $office->id);
        }

        return (int) $this->office_id;
    }
}
