<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The offices transactions are filed at (legacy `esd_casts_office`). The
     * id is the legacy `office_id`, kept so imported records still point at
     * the right office. `imis_code` is the IMIS unit code, when it differs
     * from `code`; the sign-in maps a person to an office through it.
     */
    public function up(): void
    {
        Schema::create('offices', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->string('code', 50)->unique();
            $table->string('imis_code', 50)->nullable()->index();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('office_id')->nullable()->after('role')->index();
            $table->foreign('office_id')->references('id')->on('offices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['office_id']);
            $table->dropColumn('office_id');
        });
        Schema::dropIfExists('offices');
    }
};
