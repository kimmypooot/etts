<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The places of examination / issuance offered on the certification and
     * authentication forms (docs/KIOSK_LOOKUP_DATA.md). A request stores the
     * place as text, so a name that is in use cannot be changed or deleted;
     * it can be switched off instead.
     */
    public function up(): void
    {
        Schema::create('exam_centers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->unique();
            $table->string('country', 60)->default('Philippines');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_centers');
    }
};
