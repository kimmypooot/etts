<?php

namespace Tests\Feature;

use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The header's app switcher (links to the sister ERIS systems) is for ESD only.
 */
class AppSwitcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_esd_staff_get_the_sister_system_links_built_from_the_configured_base(): void
    {
        Office::create(['id' => 1, 'name' => 'Examination Services Division', 'code' => 'ESD']);
        config(['etts.related_systems.base_url' => 'https://eris.test/esd']);

        $this->actingAs(User::factory()->admin()->create(['office_id' => 1]))->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('relatedSystems.0', ['label' => 'ONSA', 'icon' => 'grid', 'url' => 'https://eris.test/esd/db_onsa'])
                ->has('relatedSystems', 6));
    }

    public function test_field_office_staff_do_not_get_the_switcher(): void
    {
        Office::create(['id' => 2, 'name' => 'CSC Field Office - Samar', 'code' => 'FO-SAMAR']);

        $this->actingAs(User::factory()->create(['office_id' => 2]))->get('/fo/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('relatedSystems', []));
    }
}
