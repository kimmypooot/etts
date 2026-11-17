<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Auth\DevSignInController;
use App\Models\Office;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class DevSignInTest extends TestCase
{
    use RefreshDatabase;

    public function test_route_is_not_registered_outside_local(): void
    {
        config(['etts.dev_sign_in' => true]);

        $this->assertFalse(DevSignInController::enabled());
        $this->post('/dev/sign-in', ['role' => 'admin'])->assertNotFound();
    }

    public function test_sign_in_page_has_no_dev_form_outside_local(): void
    {
        config(['etts.dev_sign_in' => true]);

        foreach (['production', 'staging', 'testing'] as $env) {
            $this->app['env'] = $env;

            $this->get('/login')->assertOk()->assertInertia(fn ($page) => $page
                ->component('Auth/SignIn')
                ->where('devSignIn', null));
        }
    }

    public function test_cutover_check_fails_while_dev_sign_in_is_on(): void
    {
        config(['etts.dev_sign_in' => true]);

        $this->artisan('etts:cutover-check')
            ->expectsOutputToContain('ETTS_DEV_SIGN_IN=true')
            ->assertFailed();
    }

    public function test_refuses_when_not_local_even_if_flag_is_on(): void
    {
        config(['etts.dev_sign_in' => true]);
        Route::post('/dev/sign-in', [DevSignInController::class, 'store']);

        $this->post('/dev/sign-in', ['role' => 'admin'])->assertNotFound();
        $this->assertGuest();
    }

    public function test_signs_in_as_stub_users_when_enabled_locally(): void
    {
        $this->app['env'] = 'local';
        config(['etts.dev_sign_in' => true]);
        Route::middleware('web')->post('/dev/sign-in', [DevSignInController::class, 'store']);
        Office::create(['id' => 7, 'name' => 'FO-Test', 'code' => 'FOT', 'imis_code' => 'FOT']);

        $this->withSession(['_token' => 't'])->post('/dev/sign-in', ['role' => 'field_office', '_token' => 't'])->assertSessionHasErrors('office_id');
        $this->assertGuest();

        $this->withSession(['_token' => 't'])->post('/dev/sign-in', ['role' => 'field_office', 'office_id' => 7, '_token' => 't'])->assertRedirect();
        $this->assertAuthenticated();
        $this->assertSame(UserRole::FieldOffice, auth()->user()->role);
        $this->assertSame(7, auth()->user()->office_id);
    }
}
