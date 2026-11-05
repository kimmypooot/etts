<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An office transactions are filed at. `id` is the legacy `office_id`.
 */
#[Fillable(['id', 'name', 'code', 'imis_code'])]
class Office extends Model
{
    public $incrementing = false;

    /** The Examination Services Division itself, as opposed to a field or satellite office. */
    public function isEsd(): bool
    {
        return strtoupper(trim($this->code)) === 'ESD';
    }

    /**
     * The office an IMIS unit code belongs to: by `imis_code` first, then `code`.
     */
    public static function forImisCode(?string $code): ?self
    {
        $code = strtoupper(trim((string) $code));

        if ($code === '') {
            return null;
        }

        return self::whereRaw('upper(imis_code) = ?', [$code])->first()
            ?? self::whereRaw('upper(code) = ?', [$code])->first();
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
