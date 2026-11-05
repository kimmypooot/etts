<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The walk-in kiosks. A terminal is a device that staff have paired to an
     * office with a one-time code, so no password is ever typed on a public
     * screen (legacy asked an administrator to sign in on the kiosk itself).
     *
     *  - `kiosk_pairing_codes`: a code ESD issues for one office. Only its hash
     *    is stored; it works once and expires.
     *  - `kiosk_terminals`: a paired device. The device keeps a random token in
     *    a cookie; only the token's hash is stored here. Revoking a terminal
     *    ends it at once, on the device's next request.
     */
    public function up(): void
    {
        Schema::create('kiosk_pairing_codes', function (Blueprint $table) {
            $table->increments('id');
            $table->string('code_hash', 64)->unique();
            $table->unsignedInteger('office_id');
            $table->string('terminal_name', 80);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->foreign('office_id')->references('id')->on('offices');
        });

        Schema::create('kiosk_terminals', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 80);
            $table->unsignedInteger('office_id');
            $table->string('token_hash', 64)->unique();
            $table->foreignId('paired_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paired_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->unsignedInteger('requests_submitted')->default(0);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->foreign('office_id')->references('id')->on('offices');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kiosk_terminals');
        Schema::dropIfExists('kiosk_pairing_codes');
    }
};
