<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The eligibilities a transaction can be filed under (legacy
     * `esd_casts_eligibility_tbl`). A transaction stores the `code` as text,
     * not a foreign key, because the legacy data does (and ESD edits titles
     * freely); a code that is in use cannot be changed or deleted.
     */
    public function up(): void
    {
        Schema::create('eligibilities', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eligibilities');
    }
};
