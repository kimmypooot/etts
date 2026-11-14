<?php

namespace App\Models;

use App\Enums\AcquisitionCategory;
use App\Enums\PositionLevel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * An eligibility a transaction can be filed under. Transactions refer to it by
 * `code` (text), not by id.
 */
#[Fillable(['code', 'name', 'acquisition_category', 'position_level_equivalent', 'legal_basis'])]
class Eligibility extends Model
{
    protected $table = 'eligibilities';

    protected function casts(): array
    {
        return [
            'acquisition_category' => AcquisitionCategory::class,
            'position_level_equivalent' => PositionLevel::class,
        ];
    }

    /** Number of transactions filed under a code. */
    public static function usageCount(string $code): int
    {
        return Transaction::where('elig_code', $code)->count();
    }

    /**
     * The values the audit log records before and after a change.
     *
     * @return array<string, mixed>
     */
    public function auditValues(): array
    {
        return [
            ...$this->only('code', 'name', 'legal_basis'),
            'acquisition_category' => $this->acquisition_category?->value,
            'position_level_equivalent' => $this->position_level_equivalent?->value,
        ];
    }
}
