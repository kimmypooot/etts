<?php

namespace Tests\Unit;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use PHPUnit\Framework\TestCase;

class TransactionStatusTest extends TestCase
{
    public function test_the_ten_legacy_status_strings_are_all_covered(): void
    {
        $this->assertEqualsCanonicalizing(
            ['submitted', 'forwarded', 'received', 'Approved', 'Approved/Released', 'Disapproved', 'Returned', 'Released', 'Completed', 'Cancelled'],
            array_map(fn (TransactionStatus $status) => $status->value, TransactionStatus::cases()),
        );
    }

    public function test_the_active_and_archive_boards_are_exact_complements(): void
    {
        $active = TransactionStatus::boardActive();
        $archive = TransactionStatus::boardArchive();

        $this->assertSame([], array_values(array_filter($active, fn ($status) => in_array($status, $archive, true))));
        $this->assertEqualsCanonicalizing(TransactionStatus::cases(), [...$active, ...$archive]);
    }

    public function test_completed_is_terminal_but_stays_on_the_active_board(): void
    {
        $this->assertTrue(TransactionStatus::Completed->isTerminal());
        $this->assertContains(TransactionStatus::Completed, TransactionStatus::boardActive());
    }

    public function test_the_two_approved_statuses_count_toward_the_spels_rule(): void
    {
        $this->assertTrue(TransactionStatus::Approved->isSpelsApproved());
        $this->assertTrue(TransactionStatus::ApprovedReleased->isSpelsApproved());
        $this->assertFalse(TransactionStatus::Disapproved->isSpelsApproved());
        $this->assertFalse(TransactionStatus::Released->isSpelsApproved());
    }

    public function test_each_transaction_family_allows_only_its_own_terminal_statuses(): void
    {
        $spels = TransactionStatus::allowedFor(TransactionType::Spels);
        $this->assertContains(TransactionStatus::Approved, $spels);
        $this->assertNotContains(TransactionStatus::Released, $spels);

        foreach (TransactionType::issuance() as $type) {
            $certs = TransactionStatus::allowedFor($type);
            $this->assertContains(TransactionStatus::Released, $certs);
            $this->assertNotContains(TransactionStatus::Approved, $certs);
        }
    }

    public function test_values_gives_the_stored_strings_for_a_sql_in_list(): void
    {
        $this->assertSame(['Approved', 'Approved/Released'], TransactionStatus::values(TransactionStatus::spelsApproved()));
    }

    public function test_only_spels_is_not_an_issuance_type(): void
    {
        $this->assertFalse(TransactionType::Spels->isIssuance());
        $this->assertTrue(TransactionType::Certification->isIssuance());
        $this->assertCount(3, TransactionType::issuance());
    }
}
