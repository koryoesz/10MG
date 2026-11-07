<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Institution;
use App\Models\LoanProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerLoanHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_see_a_borrowers_loans_newest_first_with_assessments(): void
    {
        $institution = Institution::factory()->create();
        $product = LoanProduct::factory()->forInstitution($institution)->create();
        $pharmacy = $this->customer($institution);
        $older = $this->loanFor($pharmacy, $product);
        $this->travel(1)->minutes();
        $newer = $this->loanFor($pharmacy, $product);
        $this->loanFor($this->customer($institution), $product);

        $this->as($this->staff($institution, Role::LoanOfficer))
            ->getJson("/api/v1/customers/{$pharmacy->id}/loans")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id)
            ->assertJsonPath('data.0.credit_assessment.provider', 'mock-healthcare-scorer');
    }

    public function test_another_institutions_borrower_returns_404(): void
    {
        $beta = Institution::factory()->create();
        $betaBorrower = $this->customer($beta);

        $this->as($this->staff(Institution::factory()->create(), Role::InstitutionAdmin))
            ->getJson("/api/v1/customers/{$betaBorrower->id}/loans")
            ->assertNotFound();
    }

    public function test_borrowers_cannot_list_other_borrowers_loans(): void
    {
        $institution = Institution::factory()->create();
        $other = $this->customer($institution);

        $this->as($this->customer($institution)->user)
            ->getJson("/api/v1/customers/{$other->id}/loans")
            ->assertForbidden();
    }
}
