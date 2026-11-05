<?php

namespace Tests\Feature\Transactions;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\AuditLog;
use App\Models\Office;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TransactionScreensTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Office::create(['id' => 1, 'name' => 'Examination Services Division', 'code' => 'ESD']);
        Office::create(['id' => 2, 'name' => 'CSC Field Office - Samar', 'code' => 'FO-SAMAR']);
        $this->admin = User::factory()->admin()->create(['username' => 'esd.officer']);
    }

    private function make(TransactionStatus $status, array $extra = []): Transaction
    {
        return Transaction::factory()->create(['transaction_status' => $status, ...$extra]);
    }

    // ─── Access ───────────────────────────────────────────────────────────

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get('/admin/transactions')->assertRedirect('/login');
    }

    public function test_field_office_staff_are_refused_every_esd_screen_and_action(): void
    {
        $fo = User::factory()->create(['office_id' => 2]);
        $record = $this->make(TransactionStatus::Received, ['office_id' => 2]);

        $this->actingAs($fo)->get('/admin/transactions')->assertForbidden();
        $this->actingAs($fo)->get('/admin/archive')->assertForbidden();
        $this->actingAs($fo)->get("/admin/transactions/{$record->id}/timeline")->assertForbidden();
        $this->actingAs($fo)->post("/admin/transactions/{$record->id}/status", ['status' => 'Approved'])->assertForbidden();
        $this->actingAs($fo)->post('/admin/transactions/delete', ['ids' => [$record->id], 'reason' => 'OTHER', 'note' => 'No reason'])->assertForbidden();
        $this->assertSame(TransactionStatus::Received, $record->fresh()->transaction_status);
    }

    public function test_the_office_scope_keeps_a_field_office_user_to_their_own_office(): void
    {
        $mine = $this->make(TransactionStatus::Received, ['office_id' => 2]);
        $other = $this->make(TransactionStatus::Received, ['office_id' => 1]);

        $this->actingAs(User::factory()->create(['office_id' => 2]));

        $this->assertSame([$mine->id], Transaction::pluck('id')->all());
        $this->assertNull(Transaction::find($other->id));

        $this->actingAs(User::factory()->create(['office_id' => null]));
        $this->assertSame(0, Transaction::count());

        $this->actingAs($this->admin);
        $this->assertSame(2, Transaction::count());
    }

    // ─── The board ────────────────────────────────────────────────────────

    public function test_the_active_board_holds_open_work_and_history_holds_finished_work(): void
    {
        $open = $this->make(TransactionStatus::Received);
        $completed = $this->make(TransactionStatus::Completed, ['transaction_type' => TransactionType::Certification]);
        $released = $this->make(TransactionStatus::Released, ['transaction_type' => TransactionType::Certification]);
        $approved = $this->make(TransactionStatus::Approved);

        $props = fn (string $query) => $this->actingAs($this->admin)->get('/admin/transactions'.$query)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Transactions/Index'))->viewData('page')['props'];
        $ids = fn (array $props) => collect($props['transactions']['data'])->pluck('id')->sort()->values()->all();

        $active = $props('');
        $this->assertSame(collect([$open->id, $completed->id])->sort()->values()->all(), $ids($active));
        $this->assertSame(['active' => 2, 'history' => 2], $active['counts']);

        $this->assertSame(collect([$released->id, $approved->id])->sort()->values()->all(), $ids($props('?board=history')));
    }

    public function test_the_board_is_filtered_and_searched_in_sql(): void
    {
        $this->make(TransactionStatus::Received, ['lname' => 'Cruz', 'fname' => 'Ana', 'office_id' => 1, 'official_receipt' => '5550001']);
        $this->make(TransactionStatus::Forwarded, ['lname' => 'Lim', 'fname' => 'Ben', 'office_id' => 2]);
        $this->make(TransactionStatus::Received, ['lname' => 'Uy', 'fname' => 'Carl', 'office_id' => 2, 'transaction_type' => TransactionType::Authentication]);

        $ids = fn (string $query) => $this->actingAs($this->admin)->get('/admin/transactions?'.$query)->viewData('page')['props']['transactions']['data'];

        $this->assertCount(1, $ids('search=cruz'));
        $this->assertCount(1, $ids('search=5550001'));
        $this->assertCount(2, $ids('office=2'));
        $this->assertCount(1, $ids('office=2&status=forwarded'));
        $this->assertCount(1, $ids('type=Authentication'));
        $this->assertCount(3, $ids('search='));
        $this->assertCount(0, $ids('search=nobody'));
    }

    public function test_a_search_with_wildcard_characters_is_taken_literally(): void
    {
        $this->make(TransactionStatus::Received, ['lname' => 'Cruz']);

        $this->actingAs($this->admin)->get('/admin/transactions?search=%25')
            ->assertInertia(fn (Assert $page) => $page->has('transactions.data', 0));
    }

    public function test_each_row_carries_the_actions_the_workflow_allows(): void
    {
        $this->make(TransactionStatus::Forwarded);

        $this->actingAs($this->admin)->get('/admin/transactions')
            ->assertInertia(fn (Assert $page) => $page->where('transactions.data.0.actions.0.kind', 'receive'));
    }

    // ─── Actions ──────────────────────────────────────────────────────────

    public function test_receiving_through_the_endpoint_updates_the_record(): void
    {
        $record = $this->make(TransactionStatus::Forwarded);

        $this->actingAs($this->admin)->post("/admin/transactions/{$record->id}/receive", ['date' => '2026-05-02'])
            ->assertRedirect()->assertSessionHas('status');

        $this->assertSame(TransactionStatus::Received, $record->fresh()->transaction_status);
    }

    public function test_a_refused_move_comes_back_as_a_readable_error(): void
    {
        $record = $this->make(TransactionStatus::Received, ['transaction_type' => TransactionType::Certification, 'office_id' => 1]);

        $this->actingAs($this->admin)->post("/admin/transactions/{$record->id}/status", ['status' => 'Released'])
            ->assertRedirect()
            ->assertSessionHas('error', 'Invalid flow: the transaction must be completed before it can be released.');
    }

    public function test_a_bad_date_or_status_is_a_validation_error(): void
    {
        $record = $this->make(TransactionStatus::Forwarded);

        $this->actingAs($this->admin)->post("/admin/transactions/{$record->id}/receive", ['date' => 'tomorrow'])->assertSessionHasErrors('date');
        $this->actingAs($this->admin)->post("/admin/transactions/{$record->id}/status", ['status' => 'Nonsense'])->assertSessionHasErrors('status');
    }

    public function test_the_batch_endpoint_applies_a_status_to_the_selection(): void
    {
        $a = $this->make(TransactionStatus::Forwarded);
        $b = $this->make(TransactionStatus::Forwarded);

        $this->actingAs($this->admin)->post('/admin/transactions/batch-status', ['ids' => [$a->id, $b->id], 'status' => 'received'])
            ->assertSessionHas('status', '2 transaction(s) set to received.');

        $this->assertSame(2, Transaction::where('transaction_status', TransactionStatus::Received)->count());
    }

    public function test_reverting_through_the_endpoint(): void
    {
        $record = $this->make(TransactionStatus::Received);

        $this->actingAs($this->admin)->post("/admin/transactions/{$record->id}/revert", ['status' => 'forwarded'])->assertSessionHas('status');

        $this->assertSame(TransactionStatus::Forwarded, $record->fresh()->transaction_status);
    }

    public function test_deleting_needs_a_reason_and_a_note_and_archives_the_record(): void
    {
        $record = $this->make(TransactionStatus::Received);

        $this->actingAs($this->admin)->post('/admin/transactions/delete', ['ids' => [$record->id]])->assertSessionHasErrors(['reason', 'note']);
        $this->actingAs($this->admin)->post('/admin/transactions/delete', ['ids' => [$record->id], 'reason' => 'MADE_UP', 'note' => 'Because'])->assertSessionHasErrors('reason');

        $this->actingAs($this->admin)->post('/admin/transactions/delete', ['ids' => [$record->id], 'reason' => 'DUPLICATE', 'note' => 'Entered twice'])
            ->assertSessionHas('status', 'Transaction archived and removed.');

        $this->assertNull(Transaction::find($record->id));
        $this->assertDatabaseHas('deleted_transactions', ['id' => $record->id, 'deletion_reason_code' => 'DUPLICATE']);
    }

    // ─── Archive and timeline ─────────────────────────────────────────────

    public function test_the_archive_lists_deleted_records_and_filters_by_reason(): void
    {
        foreach ([['DUPLICATE', 'Cruz'], ['TEST_DATA', 'Lim']] as $i => [$code, $lname]) {
            DB::table('deleted_transactions')->insert([
                'id' => 900 + $i, 'office_id' => 1, 'fname' => 'Ana', 'lname' => $lname, 'transaction_type' => 'Special Eligibility Application',
                'transaction_date' => '2026-01-05 08:00:00', 'transaction_status' => 'Approved', 'deleted_at' => '2026-02-01 10:00:00',
                'deleted_by_name' => 'esd.officer', 'deletion_reason_code' => $code, 'deletion_reason_note' => 'Because',
            ]);
        }

        $this->actingAs($this->admin)->get('/admin/archive')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Archive/Index')->has('records.data', 2));

        $this->actingAs($this->admin)->get('/admin/archive?reason=DUPLICATE')
            ->assertInertia(fn (Assert $page) => $page->has('records.data', 1)->where('records.data.0.reason', 'Duplicate entry')->where('records.data.0.office', 'ESD'));

        $this->actingAs($this->admin)->get('/admin/archive?search=lim')
            ->assertInertia(fn (Assert $page) => $page->has('records.data', 1)->where('records.data.0.name', 'Lim, Ana'));
    }

    public function test_the_timeline_merges_legacy_history_and_new_audit_entries_newest_first(): void
    {
        $record = $this->make(TransactionStatus::Forwarded);
        DB::table('legacy_activity_logs')->insert(['id' => 1, 'record_id' => $record->id, 'username' => 'ana', 'action_type' => 'ADD_RECORD', 'details' => 'Added.', 'created_at' => '2020-01-01 08:00:00']);
        DB::table('legacy_activity_logs')->insert(['id' => 2, 'record_id' => 4242, 'username' => 'ana', 'action_type' => 'ADD_RECORD', 'details' => 'Another record.', 'created_at' => '2020-01-01 08:00:00']);

        $this->actingAs($this->admin)->post("/admin/transactions/{$record->id}/receive", ['date' => '2026-05-02']);

        $response = $this->actingAs($this->admin)->getJson("/admin/transactions/{$record->id}/timeline")->assertOk();

        $response->assertJsonCount(2, 'entries')
            ->assertJsonPath('entries.0.source', 'current')
            ->assertJsonPath('entries.0.who', 'esd.officer')
            ->assertJsonPath('entries.1.source', 'legacy')
            ->assertJsonPath('entries.1.details', 'Added.')
            ->assertJsonPath('deleted', null);
    }

    public function test_the_timeline_of_a_deleted_record_carries_its_deletion_banner(): void
    {
        $record = $this->make(TransactionStatus::Received);
        $this->actingAs($this->admin)->post('/admin/transactions/delete', ['ids' => [$record->id], 'reason' => 'CLIENT_WITHDREW', 'note' => 'Client called to withdraw']);

        $this->actingAs($this->admin)->getJson("/admin/transactions/{$record->id}/timeline")
            ->assertOk()
            ->assertJsonPath('deleted.by', 'esd.officer')
            ->assertJsonPath('deleted.reason', 'Client withdrew the request')
            ->assertJsonPath('deleted.note', 'Client called to withdraw')
            ->assertJsonPath('entries.0.action', 'transaction.deleted');
    }

    public function test_every_mutation_is_audited_against_the_record(): void
    {
        $record = $this->make(TransactionStatus::Forwarded);

        $this->actingAs($this->admin)->post("/admin/transactions/{$record->id}/receive", ['date' => '2026-05-02']);

        $this->assertSame(1, AuditLog::where('subject_id', $record->id)->where('action', 'transaction.status_changed')->count());
    }
}
