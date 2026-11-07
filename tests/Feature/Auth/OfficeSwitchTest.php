<?php

namespace Tests\Feature\Auth;

use App\Enums\TransactionStatus;
use App\Models\Office;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A director who also heads a second office picks which one they are working
 * as, then everything runs as that office; the offices come from IMIS.
 */
class OfficeSwitchTest extends TestCase
{
    use RefreshDatabase;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();

        Office::create(['id' => 1, 'name' => 'Examination Services Division', 'code' => 'ESD']);
        Office::create(['id' => 2, 'name' => 'CSC Field Office - Samar', 'code' => 'SFO', 'imis_code' => 'SFO']);
        Office::create(['id' => 3, 'name' => 'CSC Field Office - Eastern Samar', 'code' => 'ESFO', 'imis_code' => 'ESFO']);
        Office::create(['id' => 4, 'name' => 'CSC Field Office - Leyte', 'code' => 'LFO']);

        $this->director = User::factory()->create(['office_id' => 2, 'username' => 'rbuy']);
        $this->director->extraOffices()->attach(3);

        Transaction::factory()->create(['office_id' => 2, 'lname' => 'FromSamar', 'fname' => 'A', 'transaction_status' => TransactionStatus::Received]);
        Transaction::factory()->create(['office_id' => 3, 'lname' => 'FromEastern', 'transaction_status' => TransactionStatus::Received]);
    }

    private function names(): array
    {
        return collect($this->get('/fo/transactions')->viewData('page')['props']['transactions']['data'])->pluck('name')->map(fn ($n) => explode(',', $n)[0])->sort()->values()->all();
    }

    public function test_someone_with_two_offices_must_choose_before_anything_else(): void
    {
        $this->actingAs($this->director)->get('/dashboard')->assertRedirect('/office');
        $this->get('/fo/transactions')->assertRedirect('/office');

        $this->get('/office')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Auth/ChooseOffice')
            ->has('auth.user.offices', 2)
            ->where('auth.user.offices.0.code', 'ESFO'));
    }

    public function test_choosing_an_office_works_as_that_office_only(): void
    {
        $this->actingAs($this->director)->post('/office', ['office_id' => 3])->assertRedirect('/dashboard');

        $this->assertSame(['FromEastern'], $this->names());
        $this->get('/fo/dashboard')->assertInertia(fn (Assert $page) => $page->where('office.code', 'ESFO'));

        // And back again.
        $this->post('/office', ['office_id' => 2])->assertRedirect('/dashboard');
        $this->assertSame(['FromSamar'], $this->names());

        $this->assertDatabaseHas('audit_logs', ['user_id' => $this->director->id, 'action' => 'office.switched']);
    }

    public function test_the_choice_is_never_saved_to_the_account(): void
    {
        $this->actingAs($this->director)->post('/office', ['office_id' => 3]);
        $this->get('/fo/transactions')->assertOk();

        $this->assertSame(2, $this->director->fresh()->office_id);
    }

    public function test_an_office_that_is_not_theirs_is_refused(): void
    {
        $this->actingAs($this->director)->post('/office', ['office_id' => 4])->assertForbidden();
        $this->post('/office', ['office_id' => 1])->assertForbidden();

        $this->get('/dashboard')->assertRedirect('/office');
    }

    public function test_the_header_lists_the_offices_once_chosen(): void
    {
        $this->actingAs($this->director)->post('/office', ['office_id' => 3]);

        $this->get('/fo/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.office.code', 'ESFO')
            ->has('auth.user.offices', 2));
    }

    public function test_someone_with_one_office_is_never_asked(): void
    {
        $single = User::factory()->create(['office_id' => 4]);

        $this->actingAs($single)->get('/fo/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page->has('auth.user.offices', 0));
        $this->get('/office')->assertRedirect('/dashboard');
        $this->post('/office', ['office_id' => 3])->assertForbidden();
    }

    public function test_administrators_are_not_affected(): void
    {
        $admin = User::factory()->admin()->create(['office_id' => 1]);
        $admin->extraOffices()->attach(3);

        $this->actingAs($admin)->get('/dashboard')->assertOk();
    }

    public function test_signing_in_again_asks_again_and_follows_imis(): void
    {
        config(['services.imis.sso_url' => 'http://imis.test/validate', 'services.imis.audience' => 'etts.test']);
        $fake = fn (array $headed) => (function (array $stub) { Http::swap($factory = new \Illuminate\Http\Client\Factory); $factory->fake($stub); })(['http://imis.test/validate' => Http::response(['success' => true, 'user' => [
            'id' => 7, 'username' => 'rey.uy', 'fname' => 'Rey', 'lname' => 'Uy', 'role_code' => 'field_office',
            'office_code' => 'SFO', 'headed_offices' => $headed,
        ]])]);

        // Unknown offices, the person's own office and repeats are ignored.
        $fake([['code' => 'ESFO', 'name' => 'x', 'type' => 'field_office'], ['code' => 'NOPE'], ['code' => 'SFO'], ['code' => 'ESFO']]);
        $this->withSession(['active_office_id' => 3])->post('/sso', ['sso_token' => 't']);

        $user = User::where('imis_user_id', 7)->sole();
        $this->assertSame([3], $user->extraOffices()->pluck('offices.id')->all());
        $this->assertSame(2, $user->office_id);
        $this->get('/dashboard')->assertRedirect('/office'); // the earlier choice was dropped

        // The designation ended in IMIS: back to a single office.
        $fake([]);
        $this->post('/sso', ['sso_token' => 't']);
        $this->assertSame([], $user->extraOffices()->pluck('offices.id')->all());
        $this->get('/dashboard')->assertRedirect('/fo/dashboard');
    }
}
