<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The extra details a certification or authentication request carries
     * (legacy `esd_casts_issuance_requests`). One per transaction at most; SPELS
     * transactions normally have none. `transaction_id` is the legacy
     * `sets_records_id`. The attachment columns hold only a file name, the
     * file itself lives on the private `attachments` disk.
     */
    public function up(): void
    {
        Schema::create('issuance_requests', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('transaction_id')->unique();
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

            $table->foreign('transaction_id')->references('id')->on('transactions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issuance_requests');
    }
};
