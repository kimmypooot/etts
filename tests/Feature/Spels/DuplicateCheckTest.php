<?php

namespace Tests\Feature\Spels;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Office;
use App\Models\Transaction;
use App\Services\Spels\DuplicateCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DuplicateCheckTest extends TestCase
{
    use RefreshDatabase;

    private DuplicateCheck $check;

    protected function setUp(): void
    {
        parent::setUp();

        Office::create(['id' => 1, 'name' => 'Examination Services Division', 'code' => 'ESD']);
        Office::create(['id' => 2, 'name' => 'CSC Field Office - Samar', 'code' => 'FO-SAMAR']);
        $this->check = new DuplicateCheck;
    }

    private function approved(string $lname = 'Cruz', string $fname = 'Ana', ?string $mname = 'Reyes', ?string $elig = 'HGE', array $extra = []): Transaction
    {
        return Transaction::factory()->approved('2025-04-15')->forApplicant($lname, $fname, $mname, $elig)->create($extra);
    }

    public function test_an_existing_approval_blocks_the_same_applicant_and_eligibility(): void
    {
        $this->approved();

        $result = $this->check->check('Cruz', 'Ana', 'Reyes', 'HGE');

        $this->assertTrue($result->blocked);
        $this->assertSame(1, $result->approvedCount);
    }

    public function test_approved_released_counts_as_approved(): void
    {
        $this->approved(extra: ['transaction_status' => TransactionStatus::ApprovedReleased]);

        $this->assertTrue($this->check->check('Cruz', 'Ana', 'Reyes', 'HGE')->blocked);
    }

    public function test_pending_and_disapproved_records_do_not_block(): void
    {
        $this->approved(extra: ['transaction_status' => TransactionStatus::Disapproved]);
        $this->approved(extra: ['transaction_status' => TransactionStatus::Submitted]);

        $this->assertFalse($this->check->check('Cruz', 'Ana', 'Reyes', 'HGE')->blocked);
    }

    public function test_a_different_eligibility_is_unrestricted(): void
    {
        $this->approved();

        $this->assertFalse($this->check->check('Cruz', 'Ana', 'Reyes', 'BOE')->blocked);
    }

    public function test_a_null_and_an_empty_middle_name_are_the_same_person(): void
    {
        $this->approved(mname: null);

        $this->assertTrue($this->check->check('Cruz', 'Ana', '', 'HGE')->blocked);
        $this->assertTrue($this->check->check('Cruz', 'Ana', null, 'HGE')->blocked);
    }

    public function test_padded_names_still_match(): void
    {
        $this->approved(lname: '  Cruz ', fname: 'Ana  ');

        $this->assertTrue($this->check->check('Cruz', 'Ana', 'Reyes', 'HGE')->blocked);
    }

    public function test_a_different_middle_name_is_a_different_person(): void
    {
        $this->approved();

        $this->assertFalse($this->check->check('Cruz', 'Ana', 'Santos', 'HGE')->blocked);
    }

    public function test_the_check_spans_every_office(): void
    {
        $this->approved(extra: ['office_id' => 2]);

        $this->assertTrue($this->check->check('Cruz', 'Ana', 'Reyes', 'HGE')->blocked);
    }

    public function test_it_does_nothing_without_an_eligibility_or_a_name(): void
    {
        $this->approved(elig: '');

        $this->assertFalse($this->check->check('Cruz', 'Ana', 'Reyes', '')->blocked);
        $this->assertFalse($this->check->check('Cruz', 'Ana', 'Reyes', null)->blocked);
        $this->assertFalse($this->check->check('', 'Ana', 'Reyes', 'HGE')->blocked);
    }

    public function test_a_record_does_not_collide_with_itself(): void
    {
        $record = $this->approved();

        $this->assertFalse($this->check->check('Cruz', 'Ana', 'Reyes', 'HGE', $record->id)->blocked);
    }

    public function test_the_result_carries_no_contact_details(): void
    {
        $this->approved(extra: ['mobile' => '09171234567', 'email' => 'ana@example.com']);

        $record = $this->check->check('Cruz', 'Ana', 'Reyes', 'HGE')->records[0];

        $this->assertEqualsCanonicalizing(['id', 'transaction_status', 'transaction_date', 'date_completed', 'office_id'], array_keys($record));
    }

    public function test_approving_a_second_application_is_blocked(): void
    {
        $this->approved();
        $second = Transaction::factory()->forApplicant('Cruz', 'Ana', 'Reyes', 'HGE')->create();

        $this->assertTrue($this->check->blocksApproval($second->id, TransactionStatus::Approved)->blocked);
        $this->assertTrue($this->check->blocksApproval($second->id, 'Approved/Released')->blocked);
    }

    public function test_only_an_approving_transition_is_constrained(): void
    {
        $this->approved();
        $second = Transaction::factory()->forApplicant('Cruz', 'Ana', 'Reyes', 'HGE')->create();

        $this->assertFalse($this->check->blocksApproval($second->id, TransactionStatus::Disapproved)->blocked);
        $this->assertFalse($this->check->blocksApproval($second->id, 'not a status')->blocked);
        $this->assertFalse($this->check->blocksApproval($second->id, null)->blocked);
    }

    public function test_certification_records_and_missing_records_pass_the_approval_guard(): void
    {
        $this->approved();
        $cert = Transaction::factory()->forApplicant('Cruz', 'Ana', 'Reyes', 'HGE')->create(['transaction_type' => TransactionType::Certification]);

        $this->assertFalse($this->check->blocksApproval($cert->id, TransactionStatus::Approved)->blocked);
        $this->assertFalse($this->check->blocksApproval(999999, TransactionStatus::Approved)->blocked);
    }

    public function test_re_approving_the_approved_record_itself_is_not_a_duplicate(): void
    {
        $record = $this->approved();

        $this->assertFalse($this->check->blocksApproval($record->id, TransactionStatus::Approved)->blocked);
    }

    public function test_the_refusal_notice_names_the_applicant_eligibility_and_date(): void
    {
        $this->approved();

        $message = $this->check->message($this->check->check('Cruz', 'Ana', 'Reyes', 'HGE'));

        $this->assertStringContainsString('Cruz, Ana', $message);
        $this->assertStringContainsString('HGE', $message);
        $this->assertStringContainsString('Approved on 15 April 2025', $message);
        $this->assertStringContainsString('Certificate of Eligibility (COE)', $message);
    }

    public function test_the_notice_degrades_when_there_is_no_completion_date(): void
    {
        $this->approved(extra: ['date_completed' => null]);

        $message = $this->check->message($this->check->check('Cruz', 'Ana', 'Reyes', 'HGE'));

        $this->assertStringContainsString('(Approved).', $message);
    }
}
