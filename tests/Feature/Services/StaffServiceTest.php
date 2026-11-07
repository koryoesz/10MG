<?php

namespace Tests\Feature\Services;

use App\Enums\Role;
use App\Models\Institution;
use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\StaffService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Service rules tested in isolation from the database through a mocked repository.
 */
class StaffServiceTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $institution = (new Institution)->forceFill(['id' => '01JTESTINSTITUTION00000000', 'currency' => 'NGN']);
        app(TenantContext::class)->set($institution);

        $this->admin = (new User)->forceFill([
            'id' => '01JTESTADMIN0000000000000A',
            'institution_id' => $institution->id,
            'role' => Role::InstitutionAdmin,
            'is_active' => true,
        ]);
    }

    public function test_admin_cannot_deactivate_themselves_and_nothing_is_saved(): void
    {
        $this->mock(UserRepositoryInterface::class, function (MockInterface $users): void {
            $users->expects('findStaffOrFail')->andReturn($this->admin);
            $users->expects('save')->never();
            $users->expects('revokeTokens')->never();
        });

        $this->expectException(ValidationException::class);

        app(StaffService::class)->update($this->admin, $this->admin->id, ['is_active' => false]);
    }

    public function test_admin_cannot_change_their_own_role(): void
    {
        $this->mock(UserRepositoryInterface::class, function (MockInterface $users): void {
            $users->expects('findStaffOrFail')->andReturn($this->admin);
            $users->expects('save')->never();
        });

        $this->expectException(ValidationException::class);

        app(StaffService::class)->update($this->admin, $this->admin->id, ['role' => 'loan_officer']);
    }

    public function test_staff_listing_is_always_scoped_to_the_current_institution(): void
    {
        $this->mock(UserRepositoryInterface::class, function (MockInterface $users): void {
            $users->expects('staffOf')->with('01JTESTINSTITUTION00000000')->andReturn(new Collection);
        });

        $this->assertCount(0, app(StaffService::class)->list());
    }
}
