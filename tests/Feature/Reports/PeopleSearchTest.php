<?php

namespace Tests\Feature\Reports;

use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Name suggestions from the IMIS people directory. They are a convenience:
 * every path where IMIS is off or failing must leave the page working.
 */
class PeopleSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Office::create(['id' => 2, 'name' => 'CSC Field Office - Samar', 'code' => 'FO-SAMAR']);
        $this->user = User::factory()->create(['office_id' => 2]);
    }

    private function configure(): void
    {
        config(['services.imis.directory_url' => 'http://imis.test/api/v1/etts/employees', 'services.imis.directory_key' => 'secret']);
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get('/people/search?q=ana')->assertRedirect('/login');
    }

    public function test_it_is_off_without_a_key_and_says_so_without_calling_imis(): void
    {
        config(['services.imis.directory_url' => 'http://imis.test/x', 'services.imis.directory_key' => null]);
        Http::fake();

        $this->actingAs($this->user)->get('/people/search?q=ana')
            ->assertOk()->assertExactJson(['available' => false, 'results' => []]);

        Http::assertNothingSent();
    }

    public function test_it_suggests_matching_people_best_match_first_with_only_name_position_and_office(): void
    {
        $this->configure();
        Http::fake(['imis.test/*' => Http::response(['data' => [
            ['id' => 1, 'name' => 'Maria Santos', 'position' => 'Director IV', 'office_name' => 'Regional Office VIII', 'office_code' => 'RO8', 'email' => 'secret@example.test'],
            ['id' => 2, 'name' => 'Ana Reyes', 'position' => 'Attorney', 'office_name' => null],
            ['id' => 3, 'name' => 'Joan Anacleto', 'position' => null, 'office_name' => 'Leyte Field Office'],
        ]])]);

        $results = $this->actingAs($this->user)->get('/people/search?q=ana')->assertOk()->json('results');

        $this->assertSame(['Ana Reyes', 'Joan Anacleto'], array_column($results, 'name'));
        $this->assertSame(['name', 'position', 'office'], array_keys($results[0]));
        $this->assertSame('Leyte Field Office', $results[1]['office']);

        $byPosition = $this->actingAs($this->user)->get('/people/search?q=director')->json('results');
        $this->assertSame(['Maria Santos'], array_column($byPosition, 'name'));
        Http::assertSentCount(1); // the list is cached after the first call
    }

    public function test_imis_being_down_gives_no_suggestions_and_is_not_retried_on_every_keystroke(): void
    {
        $this->configure();
        Http::fake(['imis.test/*' => Http::response('boom', 500)]);

        $this->actingAs($this->user)->get('/people/search?q=ana')->assertOk()->assertJson(['available' => true, 'results' => []]);
        $this->actingAs($this->user)->get('/people/search?q=anab')->assertOk()->assertJson(['results' => []]);

        Http::assertSentCount(1);
    }

    public function test_a_blank_query_returns_nothing(): void
    {
        $this->configure();
        Http::fake();

        $this->actingAs($this->user)->get('/people/search?q=')->assertOk()->assertJson(['results' => []]);

        Http::assertNothingSent();
    }
}
