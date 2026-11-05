<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The legacy audit trail (`esd_etts_activity_logs`), imported as read-only
     * history for the timeline drawer. The app never writes here: new activity
     * goes to `audit_logs`. `user_id` is the legacy IMIS user id (not a local
     * user) and `record_id` has no foreign key, because the legacy trail
     * outlives records that were hard-deleted before the archive existed.
     */
    public function up(): void
    {
        Schema::create('legacy_activity_logs', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('record_id')->nullable()->index();
            $table->string('username', 100)->nullable();
            $table->string('action_type', 50)->index();
            $table->text('details')->nullable();
            $table->dateTime('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_activity_logs');
    }
};
