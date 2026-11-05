<?php

namespace Tests\Feature\Transactions;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\AuditLog;
use App\Models\IssuanceRequest;
use App\Models\Office;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PrintTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('attachments');
        Office::create(['id' => 1, 'name' => 'Examination Services Division', 'code' => 'ESD']);
        Office::create(['id' => 2, 'name' => 'CSC Field Office - Samar', 'code' => 'FO-SAMAR']);
        $this->admin = User::factory()->admin()->create(['username' => 'esd.officer']);
    }

    private function request(string $type = 'Certification and Authentication', array $issuance = []): Transaction
    {
        $record = Transaction::factory()->create([
            'office_id' => 1, 'transaction_type' => TransactionType::from($type), 'transaction_status' => TransactionStatus::Received,
            'lname' => 'Lim', 'fname' => 'Ben', 'mname' => 'Cruz', 'mobile' => '09171234567',
            'official_receipt' => '7654321', 'or_date' => '2026-11-03', 'transaction_details' => 'COE-2, AUTH-1',
        ]);
        IssuanceRequest::create([
            'transaction_id' => $record->id, 'current_address' => '12 Rizal St., Tacloban', 'sex' => 'Male', 'civil_status' => 'Single',
            'date_of_birth' => '1990-05-04', 'purpose_of_request' => 'Employment', 'eligibility' => 'Career Service Professional',
            'book' => 'B-12', 'page' => '34', 'sequence' => '5', 'examinee_no' => 'E-99', 'cert_no' => 'C-7', 'rating' => '81.5',
            ...$issuance,
        ]);

        return $record;
    }

    public function test_the_request_form_carries_the_legacy_sections_and_the_records_data(): void
    {
        $record = $this->request();

        $page = $this->actingAs($this->admin)->get("/admin/transactions/{$record->id}/errf")->assertOk();

        foreach (['Eligibility/Exam Records Request Form (ERRF)', 'Personal Information', 'Eligibility/Examination Data', 'Master List / ICSEVS Verification', 'Manual PSP Evaluation', 'Verifiers', 'Signature of Eligible', 'Right Thumb Print', 'Authorized Representative'] as $section) {
            $page->assertSee($section, false);
        }
        foreach (['Lim', 'Ben', 'Cruz', '12 Rizal St., Tacloban', 'Career Service Professional', 'B-12', 'E-99', '81.5', '7654321', '2026-11-03', '1990-05-04', '09171234567'] as $value) {
            $page->assertSee($value, false);
        }

        // Both boxes ticked for a combined request, and the copies read from the fee line.
        $this->assertSame(2, substr_count($page->getContent(), '☑'));
        $page->assertSeeInOrder(['COE Copies', '2', 'Auth Copies', '1'], false);
        $this->assertSame(1, AuditLog::where('action', 'transaction.printed')->count());
    }

    public function test_the_request_form_ticks_only_the_type_asked_for_and_escapes_what_was_typed(): void
    {
        $record = $this->request('Certification', ['current_address' => '<script>alert(1)</script>']);

        $page = $this->actingAs($this->admin)->get("/admin/transactions/{$record->id}/errf")->assertOk();

        $this->assertSame(1, substr_count($page->getContent(), '☑'));
        $this->assertStringNotContainsString('<script>alert(1)</script>', $page->getContent());
    }

    public function test_a_special_eligibility_application_has_no_request_form(): void
    {
        $spels = Transaction::factory()->create(['office_id' => 1, 'transaction_type' => TransactionType::Spels]);

        $this->actingAs($this->admin)->get("/admin/transactions/{$spels->id}/errf")->assertNotFound();
    }

    public function test_the_authenticated_copy_stamps_the_attached_original_with_the_signatory_and_receipt(): void
    {
        $record = $this->request();
        Storage::disk('attachments')->putFileAs('', UploadedFile::fake()->image('orig.jpg', 200, 300), 'abc123.jpg');
        $record->issuanceRequest->update(['attachment_2' => 'abc123.jpg']);

        $page = $this->actingAs($this->admin)->get("/admin/transactions/{$record->id}/authenticated-copy?".http_build_query([
            'signatory' => 'Atty. Gervien Berecio', 'position' => 'Attorney V', 'office' => 'Examination Services Division',
        ]))->assertOk();

        foreach (['CERTIFIED AUTHENTICATED COPY', 'Atty. Gervien Berecio', 'Attorney V', 'Examination Services Division', 'O.R. No. 7654321 dated November 3, 2026', "/admin/transactions/{$record->id}/attachments/2", 'qrcode.min.js'] as $text) {
            $page->assertSee($text, false);
        }
    }

    public function test_the_authenticated_copy_needs_a_signatory_and_an_attached_original(): void
    {
        $record = $this->request();

        $this->actingAs($this->admin)->get("/admin/transactions/{$record->id}/authenticated-copy")->assertSessionHasErrors('signatory');
        $this->actingAs($this->admin)->get("/admin/transactions/{$record->id}/authenticated-copy?signatory=X")->assertNotFound();

        $certOnly = $this->request('Certification');
        $this->actingAs($this->admin)->get("/admin/transactions/{$certOnly->id}/authenticated-copy?signatory=X")->assertNotFound();
    }

    public function test_a_pdf_original_is_not_stamped_but_opened(): void
    {
        $record = $this->request();
        Storage::disk('attachments')->put('scan.pdf', '%PDF-1.4');
        $record->issuanceRequest->update(['attachment_2' => 'scan.pdf']);

        $this->actingAs($this->admin)->get("/admin/transactions/{$record->id}/authenticated-copy?signatory=X")
            ->assertOk()->assertSee('Document is a PDF', false)->assertDontSee('CERTIFIED AUTHENTICATED COPY', false);
    }

    public function test_only_esd_staff_can_print_these_documents_and_other_offices_cannot_even_see_the_record(): void
    {
        $record = $this->request();
        $fo = User::factory()->create(['office_id' => 2]);

        $this->get("/admin/transactions/{$record->id}/errf")->assertRedirect('/login');
        $this->actingAs($fo)->get("/admin/transactions/{$record->id}/errf")->assertNotFound();
        $this->actingAs($fo)->get("/admin/transactions/{$record->id}/authenticated-copy?signatory=X")->assertNotFound();
    }
}
