<?php

namespace Tests\Feature\Transactions;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Office;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Transactions\TransactionActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The "action buttons by office and status" matrix: what each board offers,
 * per portal, type, status and payment.
 */
class TransactionActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $samar;

    protected function setUp(): void
    {
        parent::setUp();

        Office::create(['id' => 1, 'name' => 'Examination Services Division', 'code' => 'ESD']);
        Office::create(['id' => 2, 'name' => 'CSC Field Office - Samar', 'code' => 'FO-SAMAR']);
        $this->admin = User::factory()->admin()->create();
        $this->samar = User::factory()->create(['office_id' => 2]);
    }

    /**
     * The button keys offered for a new record.
     *
     * @param  array<string, mixed>  $extra
     * @return list<string>
     */
    private function keys(User $as, TransactionStatus $status, TransactionType $type, int $office, ?string $receipt = 'OR-1', array $extra = []): array
    {
        $record = Transaction::factory()->create(['transaction_status' => $status, 'transaction_type' => $type, 'office_id' => $office, 'official_receipt' => $receipt, ...$extra]);

        return array_column(app(TransactionActions::class)->for($record, $as), 'key');
    }

    // ─── ESD ──────────────────────────────────────────────────────────────

    public function test_esd_certification_and_authentication(): void
    {
        $cert = TransactionType::Certification;

        $this->assertSame(['process', 'cancel', 'history'], $this->keys($this->admin, TransactionStatus::Submitted, $cert, 1, null));
        $this->assertSame(['process', 'cancel', 'history'], $this->keys($this->admin, TransactionStatus::Received, $cert, 1));
        $this->assertSame(['release', 'view', 'revert', 'history'], $this->keys($this->admin, TransactionStatus::Completed, $cert, 1));
        $this->assertSame(['view', 'revert', 'history'], $this->keys($this->admin, TransactionStatus::Released, $cert, 1));
        $this->assertSame(['view', 'history'], $this->keys($this->admin, TransactionStatus::Cancelled, $cert, 1));
    }

    public function test_esd_spels(): void
    {
        $spels = TransactionType::Spels;

        // Waiting for ESD: filed here, or forwarded by an office.
        $this->assertSame(['receive', 'return', 'history'], $this->keys($this->admin, TransactionStatus::Submitted, $spels, 1));
        $this->assertSame(['receive', 'return', 'history'], $this->keys($this->admin, TransactionStatus::Forwarded, $spels, 2));
        $this->assertSame(['approve', 'disapprove', 'return', 'history'], $this->keys($this->admin, TransactionStatus::Received, $spels, 2));

        foreach ([TransactionStatus::Approved, TransactionStatus::ApprovedReleased, TransactionStatus::Disapproved, TransactionStatus::Returned] as $status) {
            $this->assertSame(['view', 'history'], $this->keys($this->admin, $status, $spels, 2), $status->value);
        }

        // Still at the field office: ESD may forward a paid one on its behalf, nothing else.
        $this->assertSame(['forward', 'history'], $this->keys($this->admin, TransactionStatus::Submitted, $spels, 2));
        $this->assertSame(['history'], $this->keys($this->admin, TransactionStatus::Submitted, $spels, 2, null));
    }

    public function test_esd_links_open_the_right_pages_and_revert_goes_one_step_back(): void
    {
        $cert = Transaction::factory()->create(['transaction_type' => TransactionType::Certification, 'transaction_status' => TransactionStatus::Completed, 'office_id' => 1]);
        $spels = Transaction::factory()->create(['transaction_status' => TransactionStatus::Forwarded, 'office_id' => 2, 'official_receipt' => 'OR-1']);

        $certActions = collect(app(TransactionActions::class)->for($cert, $this->admin))->keyBy('key');
        $spelsActions = collect(app(TransactionActions::class)->for($spels, $this->admin))->keyBy('key');

        $this->assertSame("/admin/transactions/{$cert->id}/process", $certActions['view']['href']);
        $this->assertSame(['kind' => 'revert', 'status' => 'received', 'url' => "/admin/transactions/{$cert->id}/revert"], array_intersect_key($certActions['revert'], array_flip(['kind', 'status', 'url'])));
        $this->assertSame('Released', $certActions['release']['status']);
        $this->assertSame("/admin/transactions/{$spels->id}/edit", $spelsActions['receive']['href']);
        $this->assertTrue($spelsActions['return']['needs_remarks']);
    }

    // ─── Field office ─────────────────────────────────────────────────────

    public function test_field_office_certification_and_authentication(): void
    {
        $cert = TransactionType::Certification;

        $this->assertSame(['receive', 'cancel', 'history'], $this->keys($this->samar, TransactionStatus::Submitted, $cert, 2, null));

        foreach ([TransactionStatus::Received, TransactionStatus::Completed, TransactionStatus::Released] as $status) {
            $this->assertSame(['view', 'history'], $this->keys($this->samar, $status, $cert, 2), $status->value);
        }
    }

    public function test_field_office_spels(): void
    {
        $spels = TransactionType::Spels;

        // Without an OR number: take the payment (Receive) or return it. With one: forward it.
        $this->assertSame(['receive', 'return', 'history'], $this->keys($this->samar, TransactionStatus::Submitted, $spels, 2, null));
        $this->assertSame(['forward', 'history'], $this->keys($this->samar, TransactionStatus::Submitted, $spels, 2, 'OR-1'));

        foreach ([TransactionStatus::Forwarded, TransactionStatus::Received, TransactionStatus::Approved, TransactionStatus::Disapproved, TransactionStatus::Returned] as $status) {
            $this->assertSame(['view', 'history'], $this->keys($this->samar, $status, $spels, 2), $status->value);
        }
    }

    public function test_field_office_links_and_posts_stay_in_the_field_office_portal(): void
    {
        $cert = Transaction::factory()->create(['transaction_type' => TransactionType::Certification, 'transaction_status' => TransactionStatus::Submitted, 'office_id' => 2]);
        $spels = Transaction::factory()->create(['transaction_status' => TransactionStatus::Submitted, 'office_id' => 2]);

        $certActions = collect(app(TransactionActions::class)->for($cert, $this->samar))->keyBy('key');
        $spelsActions = collect(app(TransactionActions::class)->for($spels, $this->samar))->keyBy('key');

        $this->assertSame("/fo/transactions/{$cert->id}/process", $certActions['receive']['href']);
        $this->assertSame("/fo/transactions/{$cert->id}/cancel", $certActions['cancel']['url']);
        $this->assertSame("/fo/transactions/{$spels->id}/edit", $spelsActions['receive']['href']);
        $this->assertSame("/fo/transactions/{$spels->id}/return", $spelsActions['return']['url']);
    }
}
