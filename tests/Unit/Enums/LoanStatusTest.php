<?php

namespace Tests\Unit\Enums;

use App\Enums\LoanStatus;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class LoanStatusTest extends TestCase
{
    #[TestWith([LoanStatus::Pending, LoanStatus::Approved])]
    #[TestWith([LoanStatus::Pending, LoanStatus::Rejected])]
    #[TestWith([LoanStatus::Pending, LoanStatus::Cancelled])]
    #[TestWith([LoanStatus::Approved, LoanStatus::Disbursed])]
    #[TestWith([LoanStatus::Approved, LoanStatus::Cancelled])]
    #[TestWith([LoanStatus::Disbursed, LoanStatus::Repaid])]
    public function test_allows_lifecycle_transitions(LoanStatus $from, LoanStatus $to): void
    {
        $this->assertTrue($from->canTransitionTo($to));
    }

    #[TestWith([LoanStatus::Pending, LoanStatus::Disbursed])]
    #[TestWith([LoanStatus::Approved, LoanStatus::Approved])]
    #[TestWith([LoanStatus::Rejected, LoanStatus::Approved])]
    #[TestWith([LoanStatus::Disbursed, LoanStatus::Cancelled])]
    #[TestWith([LoanStatus::Repaid, LoanStatus::Disbursed])]
    #[TestWith([LoanStatus::Cancelled, LoanStatus::Pending])]
    public function test_forbids_skipping_or_reversing_states(LoanStatus $from, LoanStatus $to): void
    {
        $this->assertFalse($from->canTransitionTo($to));
    }
}
