<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The archive of deleted transactions (legacy `esd_casts_records_deleted`
     * and `esd_casts_issuance_requests_deleted`): a deleted transaction moves
     * here instead of being destroyed. No foreign keys, on purpose: an archived
     * row must survive whatever happens to the live tables or offices.
     */
    public function up(): void
    {
        Schema::create('deleted_transactions', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
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
            $table->dateTime('deleted_at')->index();
            $table->unsignedInteger('deleted_by_id')->nullable();
            $table->string('deleted_by_name', 100)->nullable();
            $table->string('deletion_reason_code', 40)->index();
            $table->text('deletion_reason_note')->nullable();
        });

        Schema::create('deleted_issuance_requests', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('transaction_id')->index();
            $table->text('current_address')->nullable();
            $table->string('sex', 10)->nullable();
            $table->string('civil_status', 20)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('place_of_birth')->nullable();
            $table->string('employment_status', 20)->nullable();
            $table->string('company_name')->nullable();
            $table->text('company_address')->nullable();
            $table->string('purpose_of_request', 100)->nullable();
            $table->string('used_lastname')->nullable();
            $table->string('used_firstname')->nullable();
            $table->string('used_middlename')->nullable();
            $table->string('used_extn', 50)->nullable();
            $table->text('used_address')->nullable();
            $table->string('eligibility')->nullable();
            $table->date('date_of_exam_issuance')->nullable();
            $table->string('place_of_exam_issuance')->nullable();
            $table->string('book', 25)->nullable();
            $table->string('page', 5)->nullable();
            $table->string('sequence', 5)->nullable();
            $table->string('examinee_no', 50)->nullable();
            $table->string('cert_no', 15)->nullable();
            $table->string('rating', 12)->nullable();
            $table->date('effectivity_date')->nullable();
            $table->date('release_date')->nullable();
            $table->string('attachment_1')->nullable();
            $table->string('attachment_2')->nullable();
            $table->timestamps();
            $table->dateTime('deleted_at')->nullable();
            $table->unsignedInteger('deleted_by_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deleted_issuance_requests');
        Schema::dropIfExists('deleted_transactions');
    }
};
