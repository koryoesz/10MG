<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\LoanProduct;
use App\Models\LoanTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_lifecycle_step_is_audited_with_actor_and_request_id(): void
    {
        $institution = Institution::factory()->create();
        $loan = $this->loanFor($this->customer($institution), LoanProduct::factory()->forInstitution($institution)->create(), 'disbursed');

        $actions = AuditLog::query()->where('auditable_id', $loan->id)->orderBy('id')->pluck('action')->all();

        $this->assertSame(['loan.applied', 'loan.approved', 'loan.disbursed'], $actions);

        $approval = AuditLog::query()->where('action', 'loan.approved')->firstOrFail();
        $this->assertSame($loan->reviewed_by, $approval->user_id);
        $this->assertSame($institution->id, $approval->institution_id);
        $this->assertSame(['status' => 'pending'], $approval->old_values);
        $this->assertNotNull($approval->request_id);
    }

    public function test_institution_audit_endpoint_only_returns_its_own_entries(): void
    {
        [$alpha, $beta] = Institution::factory()->count(2)->create();
        $this->loanFor($this->customer($alpha), LoanProduct::factory()->forInstitution($alpha)->create());
        $this->loanFor($this->customer($beta), LoanProduct::factory()->forInstitution($beta)->create());

        $entries = $this->as($this->staff($alpha, Role::InstitutionAdmin))->getJson('/api/v1/audit-logs')->assertOk()->json('data');

        $this->assertNotEmpty($entries);
        $this->assertSame([$alpha->id], array_values(array_unique(array_column($entries, 'institution_id'))));
    }

    public function test_audit_entries_cannot_be_modified(): void
    {
        $entry = (new AuditLog)->forceFill(['action' => 'test.event']);
        $entry->save();

        $this->expectException(LogicException::class);
        $entry->forceFill(['action' => 'tampered'])->save();
    }

    public function test_ledger_entries_cannot_be_deleted(): void
    {
        $institution = Institution::factory()->create();
        $loan = $this->loanFor($this->customer($institution), LoanProduct::factory()->forInstitution($institution)->create(), 'disbursed');

        $this->expectException(LogicException::class);
        $this->unscoped(LoanTransaction::class)->where('loan_id', $loan->id)->firstOrFail()->delete();
    }

    public function test_failed_action_leaves_no_audit_entry(): void
    {
        $institution = Institution::factory()->create();
        $loan = $this->loanFor($this->customer($institution), LoanProduct::factory()->forInstitution($institution)->create());

        $this->as($this->staff($institution, Role::FinanceOfficer))->idempotentPost("/api/v1/loans/{$loan->id}/disburse")->assertConflict();

        $this->assertSame(0, AuditLog::query()->where('action', 'loan.disbursed')->count());
    }
}
