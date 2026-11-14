<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Eligibility;
use App\Models\ExamCenter;
use App\Models\Office;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExamCenterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Office::create(['id' => 1, 'name' => 'Examination Services Division', 'code' => 'ESD']);
        $this->admin = User::factory()->admin()->create();
    }

    private function useCenter(string $name): void
    {
        $transaction = Transaction::factory()->forApplicant('Cruz', 'Ana', null, 'HGE')->create();
        DB::table('issuance_requests')->insert([
            'transaction_id' => $transaction->id,
            'place_of_exam_issuance' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_an_administrator_sees_the_list_with_usage_counts(): void
    {
        ExamCenter::create(['name' => 'Tacloban City, Leyte']);
        ExamCenter::create(['name' => 'Abra']);
        $this->useCenter('Tacloban City, Leyte');

        $this->actingAs($this->admin)->get('/admin/exam-centers')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ExamCenters/Index')
                ->where('centers.data.0.name', 'Abra')
                ->where('centers.data.0.usage_count', 0)
                ->where('centers.data.0.is_active', true)
                ->where('centers.data.1.name', 'Tacloban City, Leyte')
                ->where('centers.data.1.usage_count', 1));
    }

    public function test_the_list_can_be_searched(): void
    {
        ExamCenter::create(['name' => 'Tokyo, Japan', 'country' => 'Japan']);
        ExamCenter::create(['name' => 'Abra']);

        $this->actingAs($this->admin)->get('/admin/exam-centers?search=japan')
            ->assertInertia(fn (Assert $page) => $page->has('centers.data', 1)->where('centers.data.0.name', 'Tokyo, Japan'));
    }

    public function test_field_office_staff_and_guests_cannot_reach_it(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/exam-centers')->assertForbidden();
        $this->actingAs($user)->post('/admin/exam-centers', ['name' => 'X'])->assertForbidden();
        $this->assertSame(0, ExamCenter::count());

        auth()->logout();
        $this->get('/admin/exam-centers')->assertRedirect('/login');
    }

    public function test_adding_tidies_the_name_defaults_the_country_and_is_audited(): void
    {
        $this->actingAs($this->admin)->post('/admin/exam-centers', ['name' => '  Tacloban   City, Leyte '])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('exam_centers', ['name' => 'Tacloban City, Leyte', 'country' => 'Philippines', 'is_active' => true]);
        $this->assertSame(1, AuditLog::where('action', 'exam_center.created')->count());
    }

    public function test_a_duplicate_or_empty_name_is_refused(): void
    {
        ExamCenter::create(['name' => 'Abra']);

        $this->actingAs($this->admin)->post('/admin/exam-centers', ['name' => 'Abra'])->assertSessionHasErrors('name');
        $this->actingAs($this->admin)->post('/admin/exam-centers', ['name' => ''])->assertSessionHasErrors('name');
    }

    public function test_a_place_in_use_cannot_be_renamed_or_deleted_but_can_be_switched_off(): void
    {
        $center = ExamCenter::create(['name' => 'Abra']);
        $this->useCenter('Abra');

        $this->actingAs($this->admin)->put("/admin/exam-centers/{$center->id}", ['name' => 'Abra Province', 'country' => 'Philippines', 'is_active' => true])
            ->assertSessionHasErrors('name');
        $this->assertSame('Abra', $center->fresh()->name);

        $this->actingAs($this->admin)->delete("/admin/exam-centers/{$center->id}")->assertSessionHas('error');
        $this->assertSame(1, ExamCenter::count());

        $this->actingAs($this->admin)->put("/admin/exam-centers/{$center->id}", ['name' => 'Abra', 'country' => 'Philippines', 'is_active' => false])
            ->assertSessionHasNoErrors();
        $this->assertFalse($center->fresh()->is_active);
        $this->assertSame(1, AuditLog::where('action', 'exam_center.updated')->count());
    }

    public function test_an_unused_place_can_be_renamed_and_deleted(): void
    {
        $center = ExamCenter::create(['name' => 'Old']);

        $this->actingAs($this->admin)->put("/admin/exam-centers/{$center->id}", ['name' => 'New', 'country' => 'Philippines'])->assertSessionHasNoErrors();
        $this->assertSame('New', $center->fresh()->name);

        $this->actingAs($this->admin)->delete("/admin/exam-centers/{$center->id}")->assertSessionHas('status');
        $this->assertSame(0, ExamCenter::count());
        $this->assertSame(1, AuditLog::where('action', 'exam_center.deleted')->count());
    }

    public function test_seeding_loads_the_kiosk_lists_and_can_run_again(): void
    {
        Eligibility::create(['code' => 'BOE', 'name' => 'Barangay Official']);

        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(302, ExamCenter::count());
        $this->assertSame('Philippines', ExamCenter::firstWhere('name', 'Abra')->country);
        $this->assertSame('Japan', ExamCenter::firstWhere('name', 'Tokyo, Japan')->country);
        $this->assertNotNull(ExamCenter::firstWhere('name', "Brooke's Point, Palawan"));
        $this->assertSame('Police Officer', Eligibility::firstWhere('code', 'POLICE')->name);
        $this->assertSame(6, Eligibility::count());
        $this->assertSame('Barangay Official', Eligibility::firstWhere('code', 'BOE')->name);
    }
}
