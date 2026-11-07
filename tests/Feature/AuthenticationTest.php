<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_user_logs_in_with_the_x_tenant_header(): void
    {
        $institution = Institution::factory()->create();
        $user = User::factory()->forInstitution($institution)->create(['email' => 'ops@bank.test']);

        $response = $this->postJson('/api/v1/auth/login', ['email' => 'ops@bank.test', 'password' => 'password'], ['X-Tenant' => $institution->slug]);

        $response->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.institution_id', $institution->id);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_the_same_email_at_two_institutions_resolves_to_the_tenant_in_the_header(): void
    {
        [$first, $second] = Institution::factory()->count(2)->create();
        User::factory()->forInstitution($first)->create(['email' => 'ada@example.test']);
        $atSecond = User::factory()->forInstitution($second)->create(['email' => 'ada@example.test']);

        $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.test', 'password' => 'password'], ['X-Tenant' => $second->slug])
            ->assertOk()
            ->assertJsonPath('data.user.id', $atSecond->id);
    }

    public function test_tenant_user_cannot_log_in_without_the_tenant_header_and_gets_401(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    }

    public function test_platform_operator_logs_in_without_a_tenant_header(): void
    {
        $operator = User::factory()->platformAdmin()->create();

        $this->postJson('/api/v1/auth/login', ['email' => $operator->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.user.role', 'platform_admin');
    }

    public function test_wrong_password_returns_401(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'nope'], ['X-Tenant' => $user->institution->slug])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    }

    public function test_disabled_account_returns_403(): void
    {
        $user = User::factory()->inactive()->create();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'], ['X-Tenant' => $user->institution->slug])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ACCOUNT_DISABLED');
    }

    public function test_unknown_tenant_returns_404(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'a@b.test', 'password' => 'x'], ['X-Tenant' => 'does-not-exist'])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'TENANT_NOT_FOUND');
    }

    public function test_missing_token_returns_401(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $this->postJson('/api/v1/auth/logout', [], ['Authorization' => "Bearer {$token}"])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me', ['Authorization' => "Bearer {$token}"])->assertUnauthorized();
    }

    public function test_every_response_carries_a_request_id(): void
    {
        $this->getJson('/api/v1/auth/me', ['X-Request-Id' => 'trace-12345678'])
            ->assertHeader('X-Request-Id', 'trace-12345678');
    }
}
