<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How an eligibility is classified: how it is obtained
     * (App\Enums\AcquisitionCategory), the position level it qualifies for
     * (App\Enums\PositionLevel) and the law or CSC issuance it rests on. All
     * optional: the legacy table has none of them, and the legacy import only
     * writes code and name, so it leaves what ESD enters here alone.
     */
    public function up(): void
    {
        Schema::table('eligibilities', function (Blueprint $table) {
            $table->string('acquisition_category', 20)->nullable()->after('name');
            $table->string('position_level_equivalent', 20)->nullable()->after('acquisition_category');
            $table->string('legal_basis')->nullable()->after('position_level_equivalent');
        });
    }

    public function down(): void
    {
        Schema::table('eligibilities', function (Blueprint $table) {
            $table->dropColumn(['acquisition_category', 'position_level_equivalent', 'legal_basis']);
        });
    }
};
