<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A client's request to ESD (legacy `esd_casts_records`). Ids are the
     * legacy ids, kept by the import. Column names follow the legacy table so
     * the import is a straight copy; `updated_at` is the legacy `timestamp`.
     * The child issuance request, the deleted-records archive and the
     * transmittals arrive in later phases.
     *
     * Names are matched case- and accent-insensitively by the duplicate rule,
     * as the legacy latin1_swedish_ci collation did; utf8mb4_unicode_ci keeps
     * that behaviour on MySQL/MariaDB.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('office_id')->index();
            $table->string('fname');
            $table->string('lname');
            $table->string('mname')->nullable();
            $table->string('extn', 10)->nullable();
            $table->string('sex', 20)->nullable();
            $table->string('transaction_type', 100);
            $table->dateTime('transaction_date');
            $table->text('transaction_details')->nullable();
            $table->string('elig_code')->nullable();
            $table->string('official_receipt')->nullable();
            $table->date('or_date')->nullable();
            $table->date('date_sent_to_ro')->nullable();
            $table->date('date_received_ro')->nullable();
            $table->string('transaction_status', 50);
            $table->date('date_completed')->nullable();
            $table->text('remarks')->nullable();
            $table->string('document_status', 50)->nullable();
            $table->date('doc_release_date')->nullable();
            $table->unsignedInteger('amount')->nullable();
            $table->string('mobile', 12)->nullable();
            $table->string('email', 100)->nullable();
            $table->string('action_officer', 100)->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('office_id')->references('id')->on('offices');
            $table->index(['transaction_type', 'transaction_status']);
            $table->index('transaction_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
