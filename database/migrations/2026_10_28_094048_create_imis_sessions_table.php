<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which ETTS session was started by which IMIS sign-in, so signing out
     * of IMIS can end it. Only a hash of the `sid` IMIS gave is kept.
     */
    public function up(): void
    {
        Schema::create('imis_sessions', function (Blueprint $table) {
            $table->id();
            $table->char('sid_hash', 64)->unique();
            $table->string('session_id', 100);
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imis_sessions');
    }
};
