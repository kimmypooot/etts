<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One line of the legacy audit trail, imported as read-only history. The app
 * never writes here; new activity goes to {@see AuditLog}.
 */
class LegacyActivityLog extends Model
{
    protected $guarded = [];

    public $incrementing = false;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
