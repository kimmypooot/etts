<?php

namespace Tests\Feature;

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

    public function test_a_code_in_use_cannot_be_deleted(): void
    {
        $eligibility = Eligibility::create(['code' => 'HGE', 'name' => 'Honor Graduate']);
        Transaction::factory()->forApplicant('Cruz', 'Ana', null, 'HGE')->create();

        $this->actingAs($this->admin)->delete("/admin/eligibilities/{$eligibility->id}")
            ->assertSessionHas('error', 'HGE is used by 1 record(s) and cannot be deleted.');
        $this->assertSame(1, Eligibility::count());
    }
}
