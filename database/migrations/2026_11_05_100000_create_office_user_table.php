<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The other offices a field office user works as, besides their own
     * (`users.office_id`): a director who is also officer-in-charge of a second
     * office. IMIS says which at each sign-in (`headed_offices`) and the sign-in
     * rewrites this table, so an ended designation stops applying the next time
     * the person opens ETTS.
     */
    public function up(): void
    {
        Schema::create('office_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('office_id');
            $table->foreign('office_id')->references('id')->on('offices')->cascadeOnDelete();
            $table->primary(['user_id', 'office_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('office_user');
    }
};
