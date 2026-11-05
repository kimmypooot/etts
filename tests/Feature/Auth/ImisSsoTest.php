<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ImisSsoTest extends TestCase
{
    use RefreshDatabase;

    private const VALIDATE_URL = 'http://imis.test/api/v1/sso/validate';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.imis.sso_url' => self::VALIDATE_URL,
            'services.imis.audience' => 'etts.test',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function fakeImis(array $overrides = []): void
    {
        Http::fake([self::VALIDATE_URL => Http::response([
            'success' => true,
            'user' => [
                'id' => 42,
                'username' => 'jamorden',
                'fname' => 'Juan',
                'lname' => 'Amorden',
                'email' => 'juan@example.com',
                'role_code' => 'field_office',
                'photo_url' => 'https://imis.test/photos/abc.jpg',
                ...$overrides,
            ],
        ])]);
    }

    public function test_the_sso_page_shows_the_splash_with_the_token(): void
    {
        $this->get('/sso?sso_token=abc123')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/ImisSso')->where('token', 'abc123'));
    }

    public function test_a_valid_token_creates_the_account_and_signs_in(): void
    {
        $this->fakeImis();

        $this->post('/sso', ['sso_token' => 'abc123'])->assertRedirect('/dashboard');

        $user = User::sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame(42, $user->imis_user_id);
        $this->assertSame('jamorden', $user->username);
        $this->assertSame('Juan Amorden', $user->name);
        $this->assertSame(UserRole::FieldOffice, $user->role);
        $this->assertSame('https://imis.test/photos/abc.jpg', $user->photo_url);

        Http::assertSent(fn (Request $request) => $request['token'] === 'abc123' && $request['audience'] === 'etts.test');
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'login']);
    }

    public function test_a_returning_user_gets_the_role_imis_holds_now(): void
    {
        $user = User::factory()->create(['imis_user_id' => 42, 'username' => 'jamorden', 'role' => UserRole::FieldOffice]);
        $this->fakeImis(['role_code' => 'admin']);

        $this->post('/sso', ['sso_token' => 'abc123'])->assertRedirect('/dashboard');

        $this->assertSame(UserRole::Admin, $user->fresh()->role);
        $this->assertSame(1, User::count());
    }

    public function test_sign_in_maps_the_imis_office_code_to_the_local_office(): void
    {
        Office::create(['id' => 4, 'name' => 'CSC Field Office - Eastern Samar', 'code' => 'FO-EASTERN SAMAR', 'imis_code' => 'FO-ES']);
        $this->fakeImis(['office_code' => 'fo-es']);

        $this->post('/sso', ['sso_token' => 'abc123']);

        $this->assertSame(4, User::sole()->office_id);
    }

    public function test_an_unknown_office_code_leaves_the_user_without_an_office(): void
    {
        $this->fakeImis(['office_code' => 'NOPE']);

        $this->post('/sso', ['sso_token' => 'abc123']);

        $this->assertNull(User::sole()->office_id);
    }

    public function test_someone_without_an_etts_role_is_refused(): void
    {
        $this->fakeImis(['role_code' => null]);

        $this->post('/sso', ['sso_token' => 'abc123'])
            ->assertRedirect('/login')
            ->assertSessionHas('error', 'You have not been given an ETTS role in IMIS. Please contact your administrator.');

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_a_token_imis_rejects_is_refused(): void
    {
        Http::fake([self::VALIDATE_URL => Http::response(['success' => false], 422)]);

        $this->post('/sso', ['sso_token' => 'used'])->assertRedirect('/login')->assertSessionHas('error');

        $this->assertGuest();
    }

    public function test_an_unreachable_imis_is_refused(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $this->post('/sso', ['sso_token' => 'abc123'])
            ->assertRedirect('/login')
            ->assertSessionHas('error', 'IMIS could not be reached. Please try again in a moment.');

        $this->assertGuest();
    }

    public function test_a_username_held_by_another_imis_user_is_refused(): void
    {
        User::factory()->create(['imis_user_id' => 7, 'username' => 'jamorden']);
        $this->fakeImis();

        $this->post('/sso', ['sso_token' => 'abc123'])->assertRedirect('/login')->assertSessionHas('error');

        $this->assertGuest();
    }

    public function test_sso_is_off_when_no_imis_url_is_set(): void
    {
        config(['services.imis.sso_url' => null]);

        $this->get('/sso?sso_token=abc123')->assertRedirect('/login');
    }

    public function test_guests_are_sent_to_the_sign_in_page(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');

        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/SignIn'));
    }

    public function test_signing_out_ends_the_session_and_is_audited(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
        $this->assertTrue(AuditLog::where('user_id', $user->id)->where('action', 'logout')->exists());
    }

    public function test_imis_signing_out_ends_the_etts_session_it_started(): void
    {
        $sid = str_repeat('s', 64);
        $this->fakeImis(['sid' => $sid]);
        $this->post('/sso', ['sso_token' => 'abc123'])->assertRedirect('/dashboard');

        $row = DB::table('imis_sessions')->sole();
        $this->assertSame(hash('sha256', $sid), $row->sid_hash);
        Session::getHandler()->write($row->session_id, 'payload');

        $this->postJson('/sso/logout', ['sid' => $sid])->assertOk()->assertJson(['success' => true]);

        $this->assertSame(0, DB::table('imis_sessions')->count());
        $this->assertSame('', Session::getHandler()->read($row->session_id));
    }

    public function test_an_unknown_sid_signs_nobody_out_and_is_not_an_error(): void
    {
        $this->fakeImis(['sid' => str_repeat('s', 64)]);
        $this->post('/sso', ['sso_token' => 'abc123']);

        $this->postJson('/sso/logout', ['sid' => str_repeat('x', 64)])->assertOk();
        $this->postJson('/sso/logout', ['sid' => 'short'])->assertOk();
        $this->postJson('/sso/logout')->assertOk();

        $this->assertSame(1, DB::table('imis_sessions')->count());
    }

    public function test_no_sid_from_imis_records_nothing(): void
    {
        $this->fakeImis();
        $this->post('/sso', ['sso_token' => 'abc123'])->assertRedirect('/dashboard');

        $this->assertSame(0, DB::table('imis_sessions')->count());
    }

    public function test_a_failure_recording_the_imis_session_does_not_block_sign_in(): void
    {
        Schema::drop('imis_sessions');
        $this->fakeImis(['sid' => str_repeat('s', 64)]);

        $this->post('/sso', ['sso_token' => 'abc123'])->assertRedirect('/dashboard');

        $this->assertAuthenticated();
    }

    public function test_a_different_persons_token_replaces_the_session_the_browser_still_holds(): void
    {
        $previous = User::factory()->create();
        $this->fakeImis(['id' => 99, 'username' => 'newperson']);

        $this->actingAs($previous)->post('/sso', ['sso_token' => 'abc123'])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs(User::where('imis_user_id', 99)->firstOrFail());
    }

    public function test_the_sso_page_still_takes_a_new_token_while_someone_is_signed_in(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/sso?sso_token=abc123')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/ImisSso')->where('token', 'abc123'));
        $this->get('/sso')->assertRedirect('/dashboard');
    }
}
