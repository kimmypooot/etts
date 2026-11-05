<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Transmittal batches (legacy `esd_casts_transmittals` and
     * `esd_casts_transmittal_items`). One table serves both directions:
     *  - ESD_TO_FO: ESD sends finished documents down to a field office
     *    (scoped by `target_office_id`); released when the batch is handed over.
     *  - FO_TO_RO: a field office sends SPELS applications up to the Regional
     *    Office (scoped by `origin_office_id`); transmitted when sent.
     * Every query over this table must filter by `direction`.
     *
     * An item points at a transaction by id with no foreign key, on purpose: a
     * transaction deleted into the archive must not silently remove its row from
     * a released batch, which is a record of what was sent. A row is either a
     * transaction or a manually described document (`is_manual`).
     *
     * `created_by_imis_id` is the IMIS user id the legacy system stored.
     */
    public function up(): void
    {
        Schema::create('transmittals', function (Blueprint $table) {
            $table->increments('id');
            $table->string('transmittal_no', 50)->unique();
            $table->string('direction', 12)->default('ESD_TO_FO');
            $table->unsignedInteger('target_office_id');
            $table->unsignedInteger('origin_office_id')->nullable();
            $table->string('status', 12)->default('Draft');
            $table->unsignedInteger('created_by_imis_id')->nullable();
            $table->string('created_by_name', 100)->nullable();
            $table->dateTime('created_at')->nullable();
            $table->string('remarks', 200)->default('');

            $table->foreign('target_office_id')->references('id')->on('offices');
            $table->foreign('origin_office_id')->references('id')->on('offices');
            $table->index(['direction', 'status']);
            $table->index('created_at');
        });

        Schema::create('transmittal_items', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('transmittal_id');
            $table->unsignedInteger('transaction_id')->nullable()->index();
            $table->boolean('is_manual')->default(false);
            $table->string('manual_name', 150)->nullable();
            $table->string('manual_description')->nullable();
            $table->string('manual_doc_type', 100)->nullable();
            $table->string('remarks', 200)->nullable();

            $table->foreign('transmittal_id')->references('id')->on('transmittals')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transmittal_items');
        Schema::dropIfExists('transmittals');
    }
};
