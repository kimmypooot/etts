<?php

use App\Enums\AcquisitionCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Special eligibility applications now offer only eligibilities obtained by
     * special grant, so the ones the applications have always been filed under
     * are marked as such. Only rows ESD has not classified yet are touched, and
     * ESD can change any of them on the Eligibility Types page.
     */
    private const SPECIAL_GRANTS = [
        'HGE', 'BOE', 'BHW', 'BNS', 'SKOE', 'SME 1', 'SME 2', 'EDPSE', 'MC 11',
        'CSE-PR', 'CSE-PR (JOCOSC6)', 'CSE-PR (MUP)', 'MISE', 'VPR',
    ];

    public function up(): void
    {
        DB::table('eligibilities')
            ->whereIn('code', self::SPECIAL_GRANTS)
            ->whereNull('acquisition_category')
            ->update(['acquisition_category' => AcquisitionCategory::SpecialGrant->value, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Leaves the classification in place: ESD may have confirmed it since.
    }
};
