<?php

namespace Tests\Feature;

use App\Enums\InstitutionStatus;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\InstitutionKycSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstitutionOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_pending_institution_with_admin_and_default_branding(): void
    {
        $response = $this->postJson('/api/v1/institutions', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('data.institution.slug', 'ikeja-mfb')
            ->assertJsonPath('data.institution.status', 'pending')
            ->assertJsonPath('data.institution.branding.display_name', 'Ikeja Microfinance Bank')
            ->assertJsonPath('data.admin.role', 'institution_admin')
            ->assertJsonMissingPath('data.admin.password');

        $institution = Institution::query()->where('slug', 'ikeja-mfb')->firstOrFail();
        $this->assertDatabaseHas('users', ['institution_id' => $institution->id, 'email' => 'ceo@ikeja.test', 'role' => 'institution_admin']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'institution.registered', 'institution_id' => $institution->id]);
    }

    public function test_registration_rejects_duplicate_and_malformed_slugs_with_422(): void
    {
        Institution::factory()->create(['slug' => 'ikeja-mfb']);

        $this->postJson('/api/v1/institutions', $this->payload())
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.details', ['institution.slug' => ['The institution.slug has already been taken.']]);

        $details = $this->postJson('/api/v1/institutions', $this->payload(['slug' => 'Bad Slug!']))
            ->assertUnprocessable()
            ->json('error.details');

        $this->assertSame(['The slug may only contain lowercase letters, numbers and single hyphens.'], $details['institution.slug']);
    }

    public function test_pending_institution_admin_cannot_log_in_until_approved(): void
    {
        $this->postJson('/api/v1/institutions', $this->payload())->assertCreated();

        $this->postJson('/api/v1/auth/login', ['email' => 'ceo@ikeja.test', 'password' => 'Secret123'], ['X-Tenant' => 'ikeja-mfb'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'TENANT_INACTIVE')
            ->assertJsonPath('error.details.status', 'pending');
    }

    public function test_operator_sees_a_lenders_kyc_settings_on_the_detail_view(): void
    {
        $lender = Institution::factory()->create();
        InstitutionKycSetting::query()->withoutGlobalScopes()->where('institution_id', $lender->id)
            ->update(['nin_check_enabled' => false, 'phone_match_required' => true]);
        $operator = User::factory()->platformAdmin()->create();

        $this->as($operator)->getJson("/api/v1/platform/institutions/{$lender->id}")
            ->assertOk()
            ->assertJsonPath('data.kyc_settings.kyc_required', true)
            ->assertJsonPath('data.kyc_settings.bvn_check_enabled', true)
            ->assertJsonPath('data.kyc_settings.nin_check_enabled', false)
            ->assertJsonPath('data.kyc_settings.phone_match_required', true)
            ->assertJsonPath('data.kyc_settings.enabled_checks', ['bvn']);

        $this->getJson('/api/v1/platform/institutions')
            ->assertOk()
            ->assertJsonMissingPath('data.0.kyc_settings');
    }

    public function test_a_lender_without_kyc_settings_shows_the_platform_defaults(): void
    {
        $lender = Institution::factory()->create();
        InstitutionKycSetting::query()->withoutGlobalScopes()->where('institution_id', $lender->id)->delete();

        $this->as(User::factory()->platformAdmin()->create())->getJson("/api/v1/platform/institutions/{$lender->id}")
            ->assertOk()
            ->assertJsonPath('data.kyc_settings.enabled_checks', ['bvn', 'nin'])
            ->assertJsonPath('data.kyc_settings.kyc_required', true);
    }

    public function test_platform_operator_activates_institution_which_can_then_log_in(): void
    {
        $this->postJson('/api/v1/institutions', $this->payload())->assertCreated();
        $institution = Institution::query()->where('slug', 'ikeja-mfb')->firstOrFail();
        $operator = User::factory()->platformAdmin()->create();

        $this->as($operator)
            ->postJson("/api/v1/platform/institutions/{$institution->id}/activate", ['reason' => 'KYC complete'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertNotNull($institution->fresh()->approved_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'institution.activated', 'user_id' => $operator->id]);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => 'ceo@ikeja.test', 'password' => 'Secret123'], ['X-Tenant' => 'ikeja-mfb'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['token', 'expires_at', 'user']]);
    }

    public function test_pagination_links_keep_the_status_filter(): void
    {
        Institution::factory()->pending()->create();

        $links = $this->as(User::factory()->platformAdmin()->create())
            ->getJson('/api/v1/platform/institutions?status=pending')
            ->assertOk()
            ->json('links');

        $this->assertStringContainsString('status=pending', $links['first']);
        $this->assertStringContainsString('status=pending', $links['last']);
    }

    public function test_activating_an_already_active_institution_returns_409(): void
    {
        $institution = Institution::factory()->create();

        $this->as(User::factory()->platformAdmin()->create())
            ->postJson("/api/v1/platform/institutions/{$institution->id}/activate")
            ->assertConflict()
            ->assertJsonPath('error.code', 'INVALID_INSTITUTION_STATE');
    }

    public function test_suspension_blocks_the_tenant_and_revokes_its_tokens(): void
    {
        $institution = Institution::factory()->create();
        $admin = User::factory()->forInstitution($institution)->create();
        $token = $admin->createToken('api')->plainTextToken;

        $this->as(User::factory()->platformAdmin()->create())
            ->postJson("/api/v1/platform/institutions/{$institution->id}/suspend", ['reason' => 'Regulatory hold'])
            ->assertOk()
            ->assertJsonPath('data.status', InstitutionStatus::Suspended->value);

        $this->assertSame(0, $admin->tokens()->count());

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])->assertUnauthorized();
        $this->getJson('/api/v1/tenant', ['X-Tenant' => $institution->slug])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'TENANT_INACTIVE');
    }

    public function test_institution_staff_cannot_use_platform_endpoints(): void
    {
        $institution = Institution::factory()->create();

        $this->as($this->staff($institution, Role::InstitutionAdmin))
            ->getJson('/api/v1/platform/institutions')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_platform_audit_log_lists_events_across_institutions(): void
    {
        $this->postJson('/api/v1/institutions', $this->payload())->assertCreated();

        $this->as(User::factory()->platformAdmin()->create())
            ->getJson('/api/v1/platform/audit-logs?action=institution.registered')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.new_values.slug', 'ikeja-mfb');

        $this->assertSame(1, AuditLog::query()->count());
    }

    /**
     * @param  array<string, string>  $institution
     * @return array<string, mixed>
     */
    private function payload(array $institution = []): array
    {
        return [
            'institution' => $institution + [
                'name' => 'Ikeja Microfinance Bank',
                'slug' => 'ikeja-mfb',
                'email' => 'hello@ikeja.test',
            ],
            'admin' => ['name' => 'Chief Executive', 'email' => 'ceo@ikeja.test', 'password' => 'Secret123'],
        ];
    }
}
