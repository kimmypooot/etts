<?php

namespace Tests\Feature;

use App\Enums\AcquisitionCategory;
use App\Enums\PositionLevel;
use App\Models\AuditLog;
use App\Models\Eligibility;
use App\Models\Office;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EligibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Office::create(['id' => 1, 'name' => 'Examination Services Division', 'code' => 'ESD']);
        $this->admin = User::factory()->admin()->create();
    }

    public function test_an_administrator_sees_the_list_with_usage_counts(): void
    {
        Eligibility::create(['code' => 'HGE', 'name' => 'Honor Graduate']);
        Eligibility::create(['code' => 'BOE', 'name' => 'Barangay Official']);
        Transaction::factory()->count(2)->forApplicant('Cruz', 'Ana', null, 'HGE')->create();

        $this->actingAs($this->admin)->get('/admin/eligibilities')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Eligibilities/Index')
                ->where('eligibilities.data.0.code', 'BOE')
                ->where('eligibilities.data.0.usage_count', 0)
                ->where('eligibilities.data.1.code', 'HGE')
                ->where('eligibilities.data.1.usage_count', 2));
    }

    public function test_the_list_can_be_searched(): void
    {
        Eligibility::create(['code' => 'HGE', 'name' => 'Honor Graduate']);
        Eligibility::create(['code' => 'BOE', 'name' => 'Barangay Official']);

        $this->actingAs($this->admin)->get('/admin/eligibilities?search=honor')
            ->assertInertia(fn (Assert $page) => $page->has('eligibilities.data', 1)->where('eligibilities.data.0.code', 'HGE'));
    }

    public function test_field_office_staff_cannot_reach_it(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/eligibilities')->assertForbidden();
        $this->actingAs($user)->post('/admin/eligibilities', ['code' => 'X', 'name' => 'X'])->assertForbidden();
        $this->assertSame(0, Eligibility::count());
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get('/admin/eligibilities')->assertRedirect('/login');
    }

    public function test_adding_normalises_the_code_and_is_audited(): void
    {
        $this->actingAs($this->admin)->post('/admin/eligibilities', ['code' => '  cse-pr  (mup) ', 'name' => '  CSE-Preference   Rating (MUP) '])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('eligibilities', ['code' => 'CSE-PR (MUP)', 'name' => 'CSE-Preference Rating (MUP)']);
        $this->assertSame(1, AuditLog::where('action', 'eligibility.created')->count());
    }

    public function test_codes_the_legacy_form_refused_but_the_data_holds_are_accepted(): void
    {
        foreach (['MC 11', 'SME 1', 'CSE-PR (JOCOSC6)'] as $code) {
            $this->actingAs($this->admin)->post('/admin/eligibilities', ['code' => $code, 'name' => 'Something'])->assertSessionHasNoErrors();
        }

        $this->assertSame(3, Eligibility::count());
    }

    public function test_a_duplicate_code_or_an_invalid_one_is_refused(): void
    {
        Eligibility::create(['code' => 'HGE', 'name' => 'Honor Graduate']);

        $this->actingAs($this->admin)->post('/admin/eligibilities', ['code' => 'hge', 'name' => 'Again'])->assertSessionHasErrors('code');
        $this->actingAs($this->admin)->post('/admin/eligibilities', ['code' => '<script>', 'name' => 'Bad'])->assertSessionHasErrors('code');
        $this->actingAs($this->admin)->post('/admin/eligibilities', ['code' => str_repeat('A', 21), 'name' => 'Long'])->assertSessionHasErrors('code');
        $this->actingAs($this->admin)->post('/admin/eligibilities', ['code' => 'OK', 'name' => ''])->assertSessionHasErrors('name');
    }

    public function test_the_title_of_a_code_in_use_can_change_but_the_code_cannot(): void
    {
        $eligibility = Eligibility::create(['code' => 'HGE', 'name' => 'Honor Graduate']);
        Transaction::factory()->forApplicant('Cruz', 'Ana', null, 'HGE')->create();

        $this->actingAs($this->admin)->put("/admin/eligibilities/{$eligibility->id}", ['code' => 'HGE', 'name' => 'Honor Graduate Eligibility'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Honor Graduate Eligibility', $eligibility->fresh()->name);

        $this->actingAs($this->admin)->put("/admin/eligibilities/{$eligibility->id}", ['code' => 'HGX', 'name' => 'Honor Graduate'])
            ->assertSessionHasErrors('code');
        $this->assertSame('HGE', $eligibility->fresh()->code);
    }

    public function test_an_unused_code_can_be_renamed_and_deleted(): void
    {
        $eligibility = Eligibility::create(['code' => 'OLD', 'name' => 'Old']);

        $this->actingAs($this->admin)->put("/admin/eligibilities/{$eligibility->id}", ['code' => 'NEW', 'name' => 'New'])->assertSessionHasNoErrors();
        $this->assertSame('NEW', $eligibility->fresh()->code);

        $this->actingAs($this->admin)->delete("/admin/eligibilities/{$eligibility->id}")->assertSessionHas('status');
        $this->assertSame(0, Eligibility::count());
        $this->assertSame(1, AuditLog::where('action', 'eligibility.deleted')->count());
    }

    public function test_the_list_carries_the_classification_and_the_choices(): void
    {
        Eligibility::create([
            'code' => 'HGE',
            'name' => 'Honor Graduate',
            'acquisition_category' => AcquisitionCategory::SpecialGrant,
            'position_level_equivalent' => PositionLevel::Second,
            'legal_basis' => 'PD 907',
        ]);
        Eligibility::create(['code' => 'BOE', 'name' => 'Barangay Official']);

        $this->actingAs($this->admin)->get('/admin/eligibilities')
            ->assertInertia(fn (Assert $page) => $page
                ->where('eligibilities.data.0.code', 'BOE')
                ->where('eligibilities.data.0.acquisition_category', null)
                ->where('eligibilities.data.0.acquisition_category_label', null)
                ->where('eligibilities.data.0.position_level_equivalent', null)
                ->where('eligibilities.data.0.legal_basis', null)
                ->where('eligibilities.data.1.acquisition_category', 'SPECIAL_GRANT')
                ->where('eligibilities.data.1.acquisition_category_label', 'Special grant')
                ->where('eligibilities.data.1.position_level_equivalent', 'SECOND_LEVEL')
                ->where('eligibilities.data.1.position_level_equivalent_label', 'Second level')
                ->where('eligibilities.data.1.legal_basis', 'PD 907')
                ->where('options.acquisitionCategories.0', ['value' => 'EXAM', 'label' => 'Exam'])
                ->has('options.acquisitionCategories', 3)
                ->where('options.positionLevels.2', ['value' => 'THIRD_LEVEL', 'label' => 'Third level'])
                ->has('options.positionLevels', 3));
    }

    public function test_the_classification_is_saved_and_audited(): void
    {
        $this->actingAs($this->admin)->post('/admin/eligibilities', [
            'code' => 'HGE',
            'name' => 'Honor Graduate',
            'acquisition_category' => 'SPECIAL_GRANT',
            'position_level_equivalent' => 'SECOND_LEVEL',
            'legal_basis' => '  PD   907 ',
        ])->assertSessionHasNoErrors();

        $eligibility = Eligibility::firstWhere('code', 'HGE');
        $this->assertSame(AcquisitionCategory::SpecialGrant, $eligibility->acquisition_category);
        $this->assertSame(PositionLevel::Second, $eligibility->position_level_equivalent);
        $this->assertSame('PD 907', $eligibility->legal_basis);

        $this->actingAs($this->admin)->put("/admin/eligibilities/{$eligibility->id}", [
            'code' => 'HGE',
            'name' => 'Honor Graduate',
            'acquisition_category' => 'SPECIAL_GRANT',
            'position_level_equivalent' => 'FIRST_LEVEL',
            'legal_basis' => 'PD 907',
        ])->assertSessionHasNoErrors();

        $log = AuditLog::where('action', 'eligibility.updated')->sole();
        $this->assertSame('SECOND_LEVEL', $log->before['position_level_equivalent']);
        $this->assertSame('FIRST_LEVEL', $log->after['position_level_equivalent']);
        $this->assertSame('PD 907', $log->after['legal_basis']);
    }

    public function test_the_classification_is_optional_and_blanks_are_cleared(): void
    {
        $eligibility = Eligibility::create([
            'code' => 'HGE',
            'name' => 'Honor Graduate',
            'acquisition_category' => AcquisitionCategory::SpecialGrant,
            'position_level_equivalent' => PositionLevel::Second,
            'legal_basis' => 'PD 907',
        ]);

        // A form that predates the fields (only code and name) leaves them alone.
        $this->actingAs($this->admin)->put("/admin/eligibilities/{$eligibility->id}", ['code' => 'HGE', 'name' => 'Honor Graduate Eligibility'])
            ->assertSessionHasNoErrors();
        $this->assertSame('PD 907', $eligibility->fresh()->legal_basis);

        // Blank values clear them.
        $this->actingAs($this->admin)->put("/admin/eligibilities/{$eligibility->id}", [
            'code' => 'HGE',
            'name' => 'Honor Graduate',
            'acquisition_category' => '',
            'position_level_equivalent' => '',
            'legal_basis' => '   ',
        ])->assertSessionHasNoErrors();

        $fresh = $eligibility->fresh();
        $this->assertNull($fresh->acquisition_category);
        $this->assertNull($fresh->position_level_equivalent);
        $this->assertNull($fresh->legal_basis);
    }

    public function test_an_unknown_classification_is_refused(): void
    {
        $this->actingAs($this->admin)->post('/admin/eligibilities', [
            'code' => 'HGE',
            'name' => 'Honor Graduate',
            'acquisition_category' => 'LOTTERY',
            'position_level_equivalent' => 'FOURTH_LEVEL',
            'legal_basis' => str_repeat('A', 256),
        ])->assertSessionHasErrors(['acquisition_category', 'position_level_equivalent', 'legal_basis']);

        $this->assertSame(0, Eligibility::count());
    }

    public function test_a_code_in_use_cannot_be_deleted(): void
    {
        $eligibility = Eligibility::create(['code' => 'HGE', 'name' => 'Honor Graduate']);
        Transaction::factory()->forApplicant('Cruz', 'Ana', null, 'HGE')->create();

        $this->actingAs($this->admin)->delete("/admin/eligibilities/{$eligibility->id}")
            ->assertSessionHas('error', 'HGE is used by 1 record(s) and cannot be deleted.');
        $this->assertSame(1, Eligibility::count());
    }
}
