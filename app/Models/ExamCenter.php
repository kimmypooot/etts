<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A place of examination / issuance. Requests refer to it by `name` (text), not
 * by id.
 */
#[Fillable(['name', 'country', 'is_active'])]
class ExamCenter extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** Number of requests that carry a name as their place of exam / issuance. */
    public static function usageCount(string $name): int
    {
        return DB::table('issuance_requests')->where('place_of_exam_issuance', $name)->count();
    }

    /** @return array<string, mixed> */
    public function auditValues(): array
    {
        return $this->only('name', 'country', 'is_active');
    }
}
