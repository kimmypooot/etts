<?php

namespace Tests\Feature\Transactions;

use App\Enums\TransactionStatus;
use App\Models\Transaction;
use App\Services\Transactions\ProcessSteps;
use Tests\TestCase;

class ProcessStepsTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function complete(array $extra = []): array
    {
        return [
            'lname' => 'Cruz', 'fname' => 'Ana', 'purpose_of_request' => 'Employment', 'purpose_others' => null,
            'eligibility' => 'CSE-PR', 'date_of_exam_issuance' => '2020-03-15', 'place_of_exam_issuance' => 'Tacloban',
            'book' => '12', 'page' => '34', 'sequence' => '5', 'examinee_no' => 'E-1', 'cert_no' => 'C-1', 'rating' => '85.5', 'release_date' => '2020-06-01', 'effectivity_date' => null,
            'transaction_type' => 'Certification', 'coe_copies' => 1, 'auth_copies' => 0, 'official_receipt' => 'OR-1',
            ...$extra,
        ];
    }

    public function test_step_fields_match_the_spec(): void
    {
        $this->assertSame(['book', 'page', 'sequence', 'examinee_no', 'cert_no', 'rating', 'release_date', 'effectivity_date'], ProcessSteps::fields(3));
        $this->assertContains('company_address', ProcessSteps::fields(1));
        $this->assertSame(['transaction_type', 'coe_copies', 'auth_copies', 'official_receipt', 'or_date'], ProcessSteps::fields(4));
    }

    public function test_missing_reports_required_blanks(): void
    {
        $this->assertSame(['fname', 'purpose_of_request'], ProcessSteps::missing(1, ['lname' => 'Cruz', 'fname' => '', 'purpose_of_request' => null]));
        $this->assertContains('purpose_others', ProcessSteps::missing(1, $this->complete(['purpose_of_request' => 'Others', 'purpose_others' => ''])));
        $this->assertSame([], ProcessSteps::missing(3, $this->complete()));
    }

    public function test_step_four_needs_a_copy_and_an_or(): void
    {
        $this->assertSame(
            ['official_receipt', 'coe_copies'],
            ProcessSteps::missing(4, ['transaction_type' => 'Certification', 'coe_copies' => 0, 'auth_copies' => 0, 'official_receipt' => '']),
        );
    }

    public function test_first_incomplete_step(): void
    {
        $this->assertSame(3, ProcessSteps::firstIncompleteStep($this->complete(['book' => ''])));
        $this->assertSame(1, ProcessSteps::firstIncompleteStep($this->complete(['lname' => ' '])));
        $this->assertSame(5, ProcessSteps::firstIncompleteStep($this->complete()));
    }

    public function test_lock_and_finish_action_follow_the_status(): void
    {
        $at = fn (TransactionStatus $status) => (new Transaction)->forceFill(['transaction_status' => $status]);

        $expected = [
            [TransactionStatus::Submitted, false, null],
            [TransactionStatus::Received, false, 'complete'],
            [TransactionStatus::Completed, true, 'release'],
            [TransactionStatus::Released, true, null],
            [TransactionStatus::Cancelled, true, null],
        ];

        foreach ($expected as [$status, $locked, $finish]) {
            $this->assertSame($locked, ProcessSteps::isLocked($at($status)), $status->value);
            $this->assertSame($finish, ProcessSteps::finishAction($at($status)), $status->value);
        }
    }

    public function test_rules_require_the_steps_required_fields(): void
    {
        $this->assertContains('required', ProcessSteps::rules(2)['eligibility']);
        $this->assertContains('nullable', ProcessSteps::rules(3)['effectivity_date']);
        $this->assertContains('required', ProcessSteps::rules(1, othersPurpose: true)['purpose_others']);
        $this->assertContains('nullable', ProcessSteps::rules(1)['purpose_others']);
    }
}
