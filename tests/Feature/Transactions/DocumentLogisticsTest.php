<?php

namespace Tests\Feature\Transactions;

use App\Enums\TransactionStatus;
use App\Enums\TransmittalDirection;
use App\Models\Office;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Transactions\DocumentLogistics;
use App\Services\Transactions\TransactionWorkflow;
use App\Services\Transmittals\TransmittalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentLogisticsTest extends TestCase
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

    /**
     * @return array<string, array<string, mixed>>
     */
    private function steps(Transaction $record): array
    {
        return collect(DocumentLogistics::for($record->fresh()))->keyBy('key')->all();
    }

    public function test_a_field_office_application_is_followed_up_and_back_down_with_its_batches(): void
    {
        $record = Transaction::factory()->create(['office_id' => 2, 'transaction_status' => TransactionStatus::Submitted, 'official_receipt' => 'OR-9', 'or_date' => '2026-05-02', 'elig_code' => 'HGE']);
        $service = app(TransmittalService::class);
        $workflow = app(TransactionWorkflow::class);

        $up = $service->createDraft(TransmittalDirection::FoToRo, [$record->id], '', $this->admin, 2);
        $service->transmit($up, '2026-05-03', $this->admin);
        $workflow->receive($record, '2026-05-05', $this->admin);
        $workflow->changeStatus($record, TransactionStatus::Approved, $this->admin, '2026-05-10');
        $down = $service->createDraft(TransmittalDirection::EsdToFo, [$record->id], '', $this->admin);
        $service->release($down, $this->admin);

        $steps = $this->steps($record);

        $this->assertSame(['filed', 'paid', 'forwarded', 'received', 'decided', 'released'], array_keys($steps));
        $this->assertTrue(collect($steps)->every(fn ($step) => $step['done']));
        $this->assertSame('OR OR-9', $steps['paid']['detail']);
        $this->assertSame('2026-05-03', $steps['forwarded']['date']);
        $this->assertSame("Transmittal {$up->transmittal_no}", $steps['forwarded']['detail']);
        $this->assertSame('By esd.officer', $steps['received']['detail']);
        $this->assertSame('Approved/Released', $steps['decided']['detail']);
        $this->assertSame("Transmittal {$down->transmittal_no}", $steps['released']['detail']);
    }

    public function test_an_unpaid_application_waits_for_payment(): void
    {
        $steps = $this->steps(Transaction::factory()->create(['office_id' => 2, 'transaction_status' => TransactionStatus::Submitted, 'official_receipt' => null]));

        $this->assertFalse($steps['paid']['done']);
        $this->assertSame('Awaiting payment', $steps['paid']['detail']);
        $this->assertFalse($steps['forwarded']['done']);
        $this->assertSame('Pending', $steps['decided']['detail']);
    }

    public function test_a_filing_made_at_esd_has_no_forwarding_or_release_leg(): void
    {
        $steps = $this->steps(Transaction::factory()->create(['office_id' => 1, 'transaction_status' => TransactionStatus::Received, 'official_receipt' => 'OR-1']));

        $this->assertSame(['filed', 'paid', 'received', 'decided'], array_keys($steps));
        $this->assertTrue($steps['received']['done']);
    }

    public function test_an_application_returned_before_it_was_received_was_never_received(): void
    {
        $steps = $this->steps(Transaction::factory()->create(['office_id' => 2, 'transaction_status' => TransactionStatus::Returned, 'date_received_ro' => null]));

        $this->assertFalse($steps['received']['done']);
        $this->assertTrue($steps['decided']['done']);
        $this->assertSame('Returned', $steps['decided']['detail']);
    }
}
