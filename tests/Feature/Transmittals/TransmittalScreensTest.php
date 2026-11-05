<?php

namespace Tests\Feature\Transmittals;

use App\Enums\TransactionStatus;
use App\Enums\TransmittalDirection;
use App\Models\Office;
use App\Models\Transaction;
use App\Models\Transmittal;
use App\Models\User;
use App\Services\Transmittals\TransmittalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TransmittalScreensTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Office::create(['id' => 1, 'name' => 'Examination Services Division', 'code' => 'ESD']);
        Office::create(['id' => 2, 'name' => 'CSC Field Office - Samar', 'code' => 'FO-SAMAR']);
        Office::create(['id' => 3, 'name' => 'CSC Field Office - Biliran', 'code' => 'FO-BILIRAN']);
        $this->admin = User::factory()->admin()->create();
    }

    private function record(TransactionStatus $status = TransactionStatus::Approved, int $office = 2, array $extra = []): Transaction
    {
        return Transaction::factory()->create(['transaction_status' => $status, 'office_id' => $office, ...$extra]);
    }

    private function draft(array $records): Transmittal
    {
        return app(TransmittalService::class)->createDraft(TransmittalDirection::EsdToFo, array_map(fn ($r) => $r->id, $records), '', $this->admin);
    }

    public function test_guests_and_field_office_staff_are_kept_out(): void
    {
        $draft = $this->draft([$this->record()]);
        $fo = User::factory()->create(['office_id' => 2]);

        $this->get('/admin/transmittals')->assertRedirect('/login');

        $this->actingAs($fo);
        $this->get('/admin/transmittals')->assertForbidden();
        $this->get('/admin/transmittals/create')->assertForbidden();
        $this->post('/admin/transmittals', ['ids' => [1]])->assertForbidden();
        $this->get("/admin/transmittals/{$draft->id}/slip")->assertForbidden();
        $this->post("/admin/transmittals/{$draft->id}/release")->assertForbidden();
        $this->delete("/admin/transmittals/{$draft->id}")->assertForbidden();
        $this->assertDatabaseHas('transmittals', ['id' => $draft->id, 'status' => 'Draft']);
    }

    public function test_the_list_shows_one_direction_at_a_time_with_filters(): void
    {
        $down = $this->draft([$this->record()]);
        $this->draft([$this->record(office: 3)]);
        app(TransmittalService::class)->createDraft(TransmittalDirection::FoToRo, [$this->record(TransactionStatus::Submitted)->id], '', $this->admin, 2);

        $props = fn (string $query) => $this->actingAs($this->admin)->get('/admin/transmittals'.$query)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Transmittals/Index'))->viewData('page')['props'];

        $default = $props('');
        $this->assertSame(2, $default['transmittals']['total']);
        $this->assertSame(['ESD_TO_FO' => 2, 'FO_TO_RO' => 1], $default['counts']);

        $this->assertSame(1, $props('?direction=FO_TO_RO')['transmittals']['total']);
        $this->assertSame(1, $props('?office=2')['transmittals']['total']);
        $this->assertSame(1, $props('?search='.$down->transmittal_no)['transmittals']['total']);
        $this->assertSame(0, $props('?status=Released')['transmittals']['total']);
    }

    public function test_the_create_screen_offers_only_offices_with_something_waiting(): void
    {
        $this->record(office: 2);
        $this->record(office: 2);
        $this->record(TransactionStatus::Received, office: 3);

        $this->actingAs($this->admin)->get('/admin/transmittals/create')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Transmittals/Create')
                ->has('offices', 1)
                ->where('offices.0.code', 'FO-SAMAR')
                ->where('offices.0.waiting', 2)
                ->where('records', null));

        $this->actingAs($this->admin)->get('/admin/transmittals/create?office=2')
            ->assertInertia(fn (Assert $page) => $page->where('records.total', 2)->has('records.data', 2));
    }

    public function test_building_a_batch_and_opening_it(): void
    {
        $a = $this->record();
        $b = $this->record();

        $response = $this->actingAs($this->admin)->post('/admin/transmittals', ['ids' => [$a->id, $b->id], 'remarks' => 'For release']);

        $transmittal = Transmittal::sole();
        $response->assertRedirect("/admin/transmittals/{$transmittal->id}");

        $this->actingAs($this->admin)->get("/admin/transmittals/{$transmittal->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Transmittals/Show')
                ->where('transmittal.status', 'Draft')
                ->where('transmittal.draft', true)
                ->where('transmittal.office.code', 'FO-SAMAR')
                ->has('items', 2));
    }

    public function test_a_bad_batch_comes_back_as_a_readable_error(): void
    {
        $a = $this->record(office: 2);
        $b = $this->record(office: 3);

        $this->actingAs($this->admin)->post('/admin/transmittals', ['ids' => [$a->id, $b->id]])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'different field offices'));

        $this->actingAs($this->admin)->post('/admin/transmittals', ['ids' => []])->assertSessionHasErrors('ids');
        $this->assertSame(0, Transmittal::count());
    }

    public function test_the_picker_lists_what_can_still_be_added(): void
    {
        $inside = $this->record(extra: ['lname' => 'Cruz']);
        $this->record(extra: ['lname' => 'Lim']);
        $this->record(office: 3);
        $draft = $this->draft([$inside]);

        $this->actingAs($this->admin)->getJson("/admin/transmittals/{$draft->id}/eligible")
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.name', fn ($n) => str_starts_with($n, 'Lim'));

        $this->actingAs($this->admin)->getJson("/admin/transmittals/{$draft->id}/eligible?search=zzz")->assertJsonPath('total', 0);
    }

    public function test_editing_a_draft_through_the_endpoints(): void
    {
        $record = $this->record();
        $extra = $this->record();
        $draft = $this->draft([$record]);
        $base = "/admin/transmittals/{$draft->id}";

        $this->actingAs($this->admin)->post("{$base}/records", ['transaction_id' => $extra->id])->assertSessionHas('status');
        $this->actingAs($this->admin)->post("{$base}/manual", ['manual_name' => 'Letter', 'manual_description' => 'Cover letter'])->assertSessionHas('status');
        $this->actingAs($this->admin)->post("{$base}/manual", ['manual_name' => '', 'manual_description' => ''])->assertSessionHasErrors(['manual_name', 'manual_description']);
        $this->assertSame(3, $draft->items()->count());

        $manual = $draft->items()->where('is_manual', true)->first();
        $this->actingAs($this->admin)->put("{$base}/manual/{$manual->id}", ['manual_name' => 'Letter 2', 'manual_description' => 'Revised'])->assertSessionHas('status');
        $this->assertSame('Letter 2', $manual->fresh()->manual_name);

        $this->actingAs($this->admin)->patch("{$base}/remarks", ['remarks' => 'Urgent'])->assertSessionHas('status');
        $this->assertSame('Urgent', $draft->fresh()->remarks);

        $this->actingAs($this->admin)->patch("{$base}/records/{$record->id}/note", ['field' => 'transaction_details', 'value' => 'COE-2'])->assertSessionHas('status');
        $this->assertSame('COE-2', $record->fresh()->transaction_details);
        $this->actingAs($this->admin)->patch("{$base}/records/{$record->id}/note", ['field' => 'lname', 'value' => 'x'])->assertSessionHasErrors('field');

        $this->actingAs($this->admin)->delete("{$base}/items/{$manual->id}")->assertSessionHas('status');
        $this->assertSame(2, $draft->items()->count());
    }

    public function test_releasing_and_deleting_through_the_endpoints(): void
    {
        $record = $this->record();
        $draft = $this->draft([$record]);

        $this->actingAs($this->admin)->post("/admin/transmittals/{$draft->id}/release")->assertSessionHas('status');
        $this->assertSame(TransactionStatus::ApprovedReleased, $record->fresh()->transaction_status);

        $this->actingAs($this->admin)->post("/admin/transmittals/{$draft->id}/release")
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'already been released'));

        $this->actingAs($this->admin)->delete("/admin/transmittals/{$draft->id}")->assertSessionHas('error');
        $this->assertDatabaseHas('transmittals', ['id' => $draft->id]);

        $another = $this->draft([$this->record()]);
        $this->actingAs($this->admin)->delete("/admin/transmittals/{$another->id}")->assertRedirect('/admin/transmittals');
        $this->assertDatabaseMissing('transmittals', ['id' => $another->id]);
    }

    public function test_the_slip_carries_the_items_and_the_configured_defaults(): void
    {
        config(['etts.transmittal.esd_to_fo.signatory' => 'JANE DOE', 'etts.transmittal.esd_to_fo.designation' => 'Chief']);
        $draft = $this->draft([$this->record(extra: ['lname' => 'Cruz', 'fname' => 'Ana'])]);

        $this->actingAs($this->admin)->get("/admin/transmittals/{$draft->id}/slip")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Transmittals/Slip')
                ->where('transmittal.number', $draft->transmittal_no)
                ->where('items.0.name', fn ($n) => str_starts_with($n, 'Cruz, Ana'))
                ->where('defaults.signatory', 'JANE DOE')
                ->where('defaults.designation', 'Chief')
                ->has('defaults.body')
                ->has('defaults.date'));
    }

    public function test_the_slip_defaults_hold_no_markup(): void
    {
        foreach (['esd_to_fo', 'fo_to_ro'] as $key) {
            foreach (['body', 'closing'] as $field) {
                $this->assertStringNotContainsString('<', config("etts.transmittal.{$key}.{$field}"));
            }
        }
    }
}
