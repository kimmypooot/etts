<?php

namespace Tests\Feature\Transactions;

use App\Enums\AcquisitionCategory;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Eligibility;
use App\Models\ExamCenter;
use App\Models\IssuanceRequest;
use App\Models\Office;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The staff forms take their eligibility and place of examination choices from
 * the settings lists; special eligibility offers only special grants.
 */
class LookupListsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Office::create(['id' => 1, 'name' => 'Examination Services Division', 'code' => 'ESD']);
        $this->admin = User::factory()->admin()->create();
        Eligibility::create(['code' => 'HGE', 'name' => 'Honor Graduate', 'acquisition_category' => AcquisitionCategory::SpecialGrant]);
        Eligibility::create(['code' => 'CS-PROF', 'name' => 'Career Service Professional', 'acquisition_category' => AcquisitionCategory::Exam]);
        Eligibility::create(['code' => 'XX', 'name' => 'Not Classified']);
        ExamCenter::create(['name' => 'Tacloban']);
        ExamCenter::create(['name' => 'Abra']);
        ExamCenter::create(['name' => 'Closed Center', 'is_active' => false]);
    }

    private function spelsInput(array $extra = []): array
    {
        return [
            'transaction_type' => TransactionType::Spels->value, 'office_id' => 1,
            'lname' => 'Cruz', 'fname' => 'Ana', 'transaction_date' => '2026-05-04', 'elig_code' => 'HGE',
            ...$extra,
        ];
    }

    private function certInput(array $extra = []): array
    {
        return [
            'transaction_type' => TransactionType::Certification->value, 'office_id' => 1,
            'lname' => 'Lim', 'fname' => 'Ben', 'transaction_date' => '2026-05-04', 'coe_copies' => 1, 'auth_copies' => 0,
            'eligibility' => 'Career Service Professional', 'place_of_exam_issuance' => 'Tacloban',
            ...$extra,
        ];
    }

    private function certificationWith(string $eligibility, string $place): Transaction
    {
        $transaction = Transaction::factory()->create([
            'transaction_type' => TransactionType::Certification, 'office_id' => 1, 'lname' => 'Lim', 'fname' => 'Ben',
            'transaction_status' => TransactionStatus::Received, 'transaction_details' => 'COE-1',
        ]);
        IssuanceRequest::create(['transaction_id' => $transaction->id, 'eligibility' => $eligibility, 'place_of_exam_issuance' => $place, 'date_of_exam_issuance' => '2020-03-15']);

        return $transaction;
    }

    public function test_the_form_offers_special_grants_for_spels_and_every_title_and_active_place_for_issuance(): void
    {
        $this->actingAs($this->admin)->get('/admin/transactions/create')
            ->assertInertia(fn (Assert $page) => $page
                ->where('options.eligibilities', [['value' => 'HGE', 'label' => 'HGE — Honor Graduate']])
                ->where('options.issuanceEligibilities', fn ($rows) => collect($rows)->pluck('value')->all() === ['Career Service Professional', 'Honor Graduate', 'Not Classified'])
                ->where('options.examCenters', fn ($rows) => collect($rows)->pluck('value')->all() === ['Abra', 'Tacloban']));
    }

    public function test_a_spels_application_needs_a_special_grant(): void
    {
        $this->actingAs($this->admin)->post('/admin/transactions', $this->spelsInput(['elig_code' => 'CS-PROF']))->assertSessionHasErrors('elig_code');
        $this->actingAs($this->admin)->post('/admin/transactions', $this->spelsInput(['elig_code' => 'XX']))->assertSessionHasErrors('elig_code');
        $this->actingAs($this->admin)->post('/admin/transactions', $this->spelsInput())->assertSessionHasNoErrors();
    }

    public function test_an_existing_spels_record_keeps_its_code_even_if_it_is_no_longer_a_special_grant(): void
    {
        $record = Transaction::factory()->forApplicant('Cruz', 'Ana', null, 'XX')->create(['transaction_status' => TransactionStatus::Submitted]);

        $this->actingAs($this->admin)->get("/admin/transactions/{$record->id}/edit")
            ->assertInertia(fn (Assert $page) => $page->where('options.eligibilities', fn ($rows) => collect($rows)->pluck('value')->sort()->values()->all() === ['HGE', 'XX']));

        $this->actingAs($this->admin)->put("/admin/transactions/{$record->id}", $this->spelsInput(['elig_code' => 'XX', 'version' => $record->fresh()->updated_at->format('Y-m-d H:i:s')]))
            ->assertSessionHasNoErrors();
    }

    public function test_an_issuance_request_needs_a_listed_title_and_an_active_place(): void
    {
        $this->actingAs($this->admin)->post('/admin/transactions', $this->certInput(['eligibility' => 'Made Up']))->assertSessionHasErrors('eligibility');
        $this->actingAs($this->admin)->post('/admin/transactions', $this->certInput(['place_of_exam_issuance' => 'Closed Center']))->assertSessionHasErrors('place_of_exam_issuance');
        $this->actingAs($this->admin)->post('/admin/transactions', $this->certInput())->assertSessionHasNoErrors();
    }

    public function test_the_processing_page_keeps_a_records_own_values_and_refuses_new_unlisted_ones(): void
    {
        $record = $this->certificationWith('Legacy Title', 'Old Place');
        $version = fn () => $record->fresh()->updated_at->format('Y-m-d H:i:s');

        $this->actingAs($this->admin)->get("/admin/transactions/{$record->id}/process?step=2")
            ->assertInertia(fn (Assert $page) => $page
                ->where('options.examCenters.0.value', 'Old Place')
                ->where('options.issuanceEligibilities.0.value', 'Legacy Title'));

        $this->actingAs($this->admin)->post("/admin/transactions/{$record->id}/process/2", [
            'eligibility' => 'Legacy Title', 'date_of_exam_issuance' => '2020-03-15', 'place_of_exam_issuance' => 'Old Place', 'version' => $version(),
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->post("/admin/transactions/{$record->id}/process/2", [
            'eligibility' => 'Legacy Title', 'date_of_exam_issuance' => '2020-03-15', 'place_of_exam_issuance' => 'Somewhere Else', 'version' => $version(),
        ])->assertSessionHasErrors('place_of_exam_issuance');
    }
}
