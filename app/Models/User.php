<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A staff member, as IMIS last described them. A stub: created and rewritten
 * from IMIS at each sign-in (ImisSsoController) and never given a password.
 */
#[Fillable(['imis_user_id', 'username', 'name', 'email', 'role', 'office_id', 'photo_url', 'last_login_at'])]
#[Hidden(['remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    /**
     * The person's own office, kept here while `office_id` holds the office they
     * are working as this request (ApplyActiveOffice). Never stored.
     */
    public ?int $homeOfficeId = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'imis_user_id' => 'integer',
            'role' => UserRole::class,
            'last_login_at' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /**
     * @return BelongsTo<Office, $this>
     */
    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /**
     * The other offices this person works as (see the office_user migration).
     *
     * @return BelongsToMany<Office, $this>
     */
    public function extraOffices(): BelongsToMany
    {
        return $this->belongsToMany(Office::class, 'office_user');
    }

    /**
     * Every office a field office user may work as: their own plus the extra
     * ones, own office first. Always empty for administrators, who see every
     * office anyway.
     *
     * @return list<int>
     */
    public function workOfficeIds(): array
    {
        if ($this->role !== UserRole::FieldOffice) {
            return [];
        }

        return collect([$this->homeOfficeId ?? $this->office_id])
            ->merge($this->extraOffices()->pluck('offices.id'))
            ->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
    }
}
