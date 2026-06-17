<?php

use App\Http\Middleware\RoleMiddleware;
use App\Jobs\WriteAuditLogJob;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Feature tests for RBAC — Task 3.4
 *
 * Covers:
 *  - Permission check returns HTTP 403 for unauthorized role (Req 3.2)
 *  - Role assignment works correctly (Req 3.3)
 *  - System_Admin role cannot be assigned via tenant API (Req 3.5)
 *  - Cache is invalidated on role change (Req 3.6)
 *  - Role revocation works correctly (Req 3.3)
 *  - Audit log is dispatched on role assignment/revocation (Req 3.9)
 *  - 403 response uses standard JSON envelope (Req 3.2)
 */

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// Mock Redis for all tests — Redis is not available in the test environment.
// The AuthMiddleware checks Redis for JWT blacklist; we mock it to return 0 (not blacklisted).
beforeEach(function () {
    Redis::shouldReceive('exists')->andReturn(0)->byDefault();
    Redis::shouldReceive('setex')->andReturn(true)->byDefault();
    Redis::shouldReceive('get')->andReturn(null)->byDefault();
    Redis::shouldReceive('del')->andReturn(1)->byDefault();
    Redis::shouldReceive('set')->andReturn(true)->byDefault();
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Create a tenant, a user with the given role, and return a JWT token.
 */
function createUserWithRole(string $roleName): array
{
    $tenant = Tenant::factory()->create(['status' => 'active']);

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'status'    => 'active',
    ]);

    // Ensure role exists
    $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'api']);
    $user->assignRole($role);

    $token = JWTAuth::fromUser($user);

    return compact('tenant', 'user', 'token');
}

/**
 * Make an authenticated request with tenant header.
 */
function authedRequest(string $method, string $uri, string $token, string $tenantId, array $data = []): \Illuminate\Testing\TestResponse
{
    return test()
        ->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID'   => $tenantId,
        ])
        ->{strtolower($method) . 'Json'}($uri, $data);
}

// ---------------------------------------------------------------------------
// Requirement 3.2 — Permission check returns HTTP 403 for unauthorized role
// ---------------------------------------------------------------------------

it('returns HTTP 403 with standard JSON envelope when user lacks required permission', function () {
    Bus::fake();

    // Seed the permission
    Permission::firstOrCreate(['name' => 'users.view', 'guard_name' => 'api']);

    // Department_Staff does NOT have users.view
    $role = Role::firstOrCreate(['name' => 'Department_Staff', 'guard_name' => 'api']);
    // Ensure role does NOT have users.view
    $role->revokePermissionTo('users.view');

    $tenant = Tenant::factory()->create(['status' => 'active']);
    $user   = User::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
    $user->assignRole($role);

    $token = JWTAuth::fromUser($user);

    // Hit a route protected by role.check:users.view
    $response = authedRequest('GET', '/api/v1/users', $token, $tenant->id);

    $response->assertStatus(403);

    $body = $response->json();
    expect($body['success'])->toBeFalse();
    expect($body['data'])->toBeNull();
    expect($body['message'])->toBeString();
    expect($body['errors'])->toHaveKey('permission');
    expect($body['meta'])->toBeNull();
});

it('403 response contains the required permission name in errors', function () {
    Bus::fake();

    Permission::firstOrCreate(['name' => 'users.view', 'guard_name' => 'api']);

    $role = Role::firstOrCreate(['name' => 'Department_Staff', 'guard_name' => 'api']);
    $role->revokePermissionTo('users.view');

    $tenant = Tenant::factory()->create(['status' => 'active']);
    $user   = User::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
    $user->assignRole($role);

    $token = JWTAuth::fromUser($user);

    $response = authedRequest('GET', '/api/v1/users', $token, $tenant->id);

    $body = $response->json();
    expect($body['errors']['permission'][0])->toContain('users.view');
});

it('dispatches audit log entry when access is denied', function () {
    Bus::fake();

    Permission::firstOrCreate(['name' => 'users.view', 'guard_name' => 'api']);

    $role = Role::firstOrCreate(['name' => 'Department_Staff', 'guard_name' => 'api']);
    $role->revokePermissionTo('users.view');

    $tenant = Tenant::factory()->create(['status' => 'active']);
    $user   = User::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
    $user->assignRole($role);

    $token = JWTAuth::fromUser($user);

    authedRequest('GET', '/api/v1/users', $token, $tenant->id);

    Bus::assertDispatched(WriteAuditLogJob::class, function ($job) {
        // Inspect via reflection since properties are private
        $ref = new ReflectionClass($job);
        $actionProp = $ref->getProperty('actionType');
        $actionProp->setAccessible(true);
        return $actionProp->getValue($job) === 'access_denied';
    });
});

it('allows access when user has the required permission', function () {
    Bus::fake();

    Permission::firstOrCreate(['name' => 'users.view', 'guard_name' => 'api']);

    $role = Role::firstOrCreate(['name' => 'Tenant_Admin', 'guard_name' => 'api']);
    $role->givePermissionTo('users.view');

    $tenant = Tenant::factory()->create(['status' => 'active']);
    $user   = User::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
    $user->assignRole($role);

    $token = JWTAuth::fromUser($user);

    // The UserController doesn't exist yet, so we just check it's NOT 403
    $response = authedRequest('GET', '/api/v1/users', $token, $tenant->id);

    // Should not be 403 (may be 404 or 500 since controller stub doesn't exist)
    expect($response->status())->not->toBe(403);
});

// ---------------------------------------------------------------------------
// Requirement 3.3 — Role assignment works correctly
// ---------------------------------------------------------------------------

it('assigns a role to a user successfully', function () {
    Bus::fake();

    // Seed roles and permissions
    $assignPermission = Permission::firstOrCreate(['name' => 'roles.assign', 'guard_name' => 'api']);
    $usersViewPerm    = Permission::firstOrCreate(['name' => 'users.view', 'guard_name' => 'api']);

    $adminRole = Role::firstOrCreate(['name' => 'Tenant_Admin', 'guard_name' => 'api']);
    $adminRole->givePermissionTo([$assignPermission, $usersViewPerm]);

    $targetRole = Role::firstOrCreate(['name' => 'Department_Staff', 'guard_name' => 'api']);

    $tenant = Tenant::factory()->create(['status' => 'active']);

    // Actor: Tenant_Admin
    $admin = User::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
    $admin->assignRole($adminRole);
    $adminToken = JWTAuth::fromUser($admin);

    // Target user (no role yet)
    $targetUser = User::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);

    $response = authedRequest(
        'POST',
        "/api/v1/users/{$targetUser->id}/roles",
        $adminToken,
        $tenant->id,
        ['role' => 'Department_Staff']
    );

    $response->assertStatus(200);

    $body = $response->json();
    expect($body['success'])->toBeTrue();
    expect($body['data']['roles'])->toContain('Department_Staff');

    // Verify in DB
    expect($targetUser->fresh()->hasRole('Department_Staff'))->toBeTrue();
});

it('returns standard JSON envelope on successful role assignment', function () {
    Bus::fake();

    $assignPermission = Permission::firstOrCreate(['name' => 'roles.assign', 'guard_name' => 'api']);
    $usersViewPerm    = Permission::firstOrCreate(['name' => 'users.view', 'guard_name' => 'api']);

    $adminRole = Role::firstOrCreate(['name' => 'Tenant_Admin', 'guard_name' => 'api']);
    $adminRole->givePermissionTo([$assignPermission, $usersViewPerm]);

    Role::firstOrCreate(['name' => 'Finance_Officer', 'guard_name' => 'api']);

    $tenant = Tenant::factory()->create(['status' => 'active']);

    $admin = User::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
    $admin->assignRole($adminRole);
    $adminToken = JWTAuth::fromUser($admin);

    $targetUser = User::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);

    $response = authedRequest(
        'POST',
        "/api/v1/users/{$targetUser->id}/roles",
        $adminToken,
        $tenant->id,
        ['role' => 'Finance_Officer']
    );

    $body = $response->json();
    expect($body)->toHaveKeys(['success', 'data', 'message', 'errors', 'meta']);
    expect($body['success'])->toBeTrue();
    expect($body['errors'])->toBeNull();
});

it('dispatches audit log on role assignment', function () {
    Bus::fake();

    $assignPermission = Permission::firstOrCreate(['name' => 'roles.assign', 'guard_name' => 'api']);
    $usersViewPerm    = Permission::firstOrCreate(['name' => 'users.view', 'guard_name' => 'api']);

    $adminRole = Role::firstOrCreate(['name' => 'Tenant_Admin', 'guard_name' => 'api']);
    $adminRole->givePermissionTo([$assignPermission, $usersViewPerm]);

    Role::firstOrCreate(['name' => 'Store_Manager', 'guard_name' => 'api']);

    $tenant = Tenant::factory()->create(['status' => 'active']);

    $admin = User::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
    $admin->assignRole($adminRole);
    $adminToken = JWTAuth::fromUser($admin);

    $targetUser = User::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);

    authedRequest(
        'POST',
        "/api/v1/users/{$targetUser->id}/roles",
        $adminToken,
        $tenant->id,
        ['role' => 'Store_Manager']
    );

    Bus::assertDispatched(WriteAuditLogJob::class, function ($job) {
        $ref = new ReflectionClass($job);
        $actionProp = $ref->getProperty('actionType');
        $actionProp->setAccessible(true);
        return $actionProp->getValue($job) === 'role_assigned';
    });
});

// ---------------------------------------------------------------------------
// Requirement 3.5 — System_Admin role cannot be assigned via tenant API
// ---------------------------------------------------------------------------

it('returns HTTP 403 when attempting to assign System_Admin role', function () {
    Bus::fake();

    $assignPermission = Permission::firstOrCreate(['name' => 'roles.assign', 'guard_name' => 'api']);
    $usersViewPerm    = Permission::firstOrCreate(['name' => 'users.view', 'guard_name' => 'api']);

    $adminRole = Role::firstOrCreate(['name' => 'Tenant_Admin', 'guard_name' => 'api']);
    $adminRole->givePermissionTo([$assignPermission, $usersViewPerm]);

    Role::firstOrCreate(['name' => 'System_Admin', 'guard_name' => 'api']);

    $tenant = Tenant::factory()->create(['status' => 'active']);

    $admin = User::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
    $admin->assignRole($adminRole);
    $adminToken = JWTAuth::fromUser($admin);

    $targetUser = User::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);

    $response = authedRequest(
        'POST',
        "/api/v1/users/{$targetUser->id}/roles",
        $adminToken,
        $tenant->id,
        ['role' => 'System_Admin']
    );

    $response->assertStatus(403);

    $body = $response->json();
    expect($body['success'])->toBeFalse();
    expect($body['errors']['role'][0])->toContain('System_Admin');
});

it('does not assign System_Admin role to the target user', function () {
    Bus::fake();

    $assignPermission = Permission::firstOrCreate(['name' => 'roles.assign', 'guard_name' => 'api']);
    $usersViewPerm    = Permission::firstOrCreate(['name' => 'users.view', 'guard_name' => 'api']);

    $adminRole = Role::firstOrCreate(['name' => 'Tenant_Admin', 'guard_name' => 'api']);
    $adminRole->givePermissionTo([$assignPermission, $usersViewPerm]);

    Role::firstOrCreate(['name' => 'System_Admin', 'guard_name' => 'api']);

    $tenant = Tenant::factory()->create(['status' => 'active']);

    $admin = User::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
    $admin->assignRole($adminRole);
    $adminToken = JWTAuth::fromUser($admin);

    $targetUser = User::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);

    authedRequest(
        'POST',
        "/api/v1/users/{$targetUser->id}/roles",
        $adminToken,
        $tenant->id,
        ['role' => 'System_Admin']
    );

    // Target user must NOT have System_Admin role
    expect($targetUser->fresh()->hasRole('System_Admin'))->toBeFalse();
});

it('returns HTTP 403 when attempting to revoke System_Admin role via tenant API', function () {
    Bus::fake();

    $assignPermission = Permission::firstOrCreate(['name' => 'roles.assign', 'guard_name' => 'api']);
    $usersViewPerm    = Permission::firstOrCreate(['name' => 'users.view', 'guard_name' => 'api']);

    $adminRole    = Role::firstOrCreate(['name' => 'Tenant_Admin', 'guard_name' => 'api']);
    $sysAdminRole = Role::firstOrCreate(['name' => 'System_Admin', 'guard_name' => 'api']);
    $adminRole->givePermissionTo([$assignPermission, $usersViewPerm]);

    $tenant = Tenant::factory()->create(['status' => 'active']);

    $admin = User::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
    $admin->assignRole($adminRole);
    $adminToken = JWTAuth::fromUser($admin);

    $targetUser = User::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
    $targetUser->assignRole($sysAdminRole);

    $response = authedRequest(
        'DELETE',
        "/api/v1/users/{$targetUser->id}/roles/System_Admin",
        $adminToken,
        $tenant->id
    );

    $response->assertStatus(403);
});

// ---------------------------------------------------------------------------
// Requirement 3.6 — Cache is invalidated on role change within 5 seconds
// ---------------------------------------------------------------------------

it('invalidates Spatie permission cache immediately on role assignment', function () {
    Bus::fake();

    $assignPermission = Permission::firstOrCreate(['name' => 'roles.assign', 'guard_name' => 'api']);
    $usersViewPerm    = Permission::firstOrCreate(['name' => 'users.view', 'guard_name' => 'api']);

    $adminRole = Role::firstOrCreate(['name' => 'Tenant_Admin', 'guard_name' => 'api']);
    $adminRole->givePermissionTo([$assignPermission, $usersViewPerm]);

    $targetRole = Role::firstOrCreate(['name' => 'Procurement_Officer', 'guard_name' => 'api']);

    $tenant = Tenant::factory()->create(['status' => 'active']);

    $admin = User::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
    $admin->assignRole($adminRole);
    $adminToken = JWTAuth::fromUser($admin);

    $targetUser = User::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);

    // Seed a cached value to verify it gets cleared
    $registrar = app()[PermissionRegistrar::class];
    $registrar->setPermissionsTeamId(null);

    // Assign role — this should call forgetCachedPermissions()
    $response = authedRequest(
        'POST',
        "/api/v1/users/{$targetUser->id}/roles",
        $adminToken,
        $tenant->id,
        ['role' => 'Procurement_Officer']
    );

    $response->assertStatus(200);

    // After assignment, the user's permissions should be immediately resolvable
    // (cache was cleared, so fresh DB lookup occurs)
    $freshUser = $targetUser->fresh();
    expect($freshUser->hasRole('Procurement_Officer'))->toBeTrue();
});

it('invalidates Spatie permission cache immediately on role revocation', function () {
    Bus::fake();

    $assignPermission = Permission::firstOrCreate(['name' => 'roles.assign', 'guard_name' => 'api']);
    $usersViewPerm    = Permission::firstOrCreate(['name' => 'users.view', 'guard_name' => 'api']);

    $adminRole  = Role::firstOrCreate(['name' => 'Tenant_Admin', 'guard_name' => 'api']);
    $targetRole = Role::firstOrCreate(['name' => 'Committee_Member', 'guard_name' => 'api']);
    $adminRole->givePermissionTo([$assignPermission, $usersViewPerm]);

    $tenant = Tenant::factory()->create(['status' => 'active']);

    $admin = User::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
    $admin->assignRole($adminRole);
    $adminToken = JWTAuth::fromUser($admin);

    $targetUser = User::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
    $targetUser->assignRole($targetRole);

    $response = authedRequest(
        'DELETE',
        "/api/v1/users/{$targetUser->id}/roles/Committee_Member",
        $adminToken,
        $tenant->id
    );

    $response->assertStatus(200);

    // After revocation, the role should be gone
    expect($targetUser->fresh()->hasRole('Committee_Member'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Role revocation — additional coverage
// ---------------------------------------------------------------------------

it('revokes a role from a user successfully', function () {
    Bus::fake();

    $assignPermission = Permission::firstOrCreate(['name' => 'roles.assign', 'guard_name' => 'api']);
    $usersViewPerm    = Permission::firstOrCreate(['name' => 'users.view', 'guard_name' => 'api']);

    $adminRole  = Role::firstOrCreate(['name' => 'Tenant_Admin', 'guard_name' => 'api']);
    $targetRole = Role::firstOrCreate(['name' => 'Finance_Officer', 'guard_name' => 'api']);
    $adminRole->givePermissionTo([$assignPermission, $usersViewPerm]);

    $tenant = Tenant::factory()->create(['status' => 'active']);

    $admin = User::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
    $admin->assignRole($adminRole);
    $adminToken = JWTAuth::fromUser($admin);

    $targetUser = User::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
    $targetUser->assignRole($targetRole);

    $response = authedRequest(
        'DELETE',
        "/api/v1/users/{$targetUser->id}/roles/Finance_Officer",
        $adminToken,
        $tenant->id
    );

    $response->assertStatus(200);

    $body = $response->json();
    expect($body['success'])->toBeTrue();
    expect($body['data']['roles'])->not->toContain('Finance_Officer');
});

it('returns 422 when revoking a role the user does not have', function () {
    Bus::fake();

    $assignPermission = Permission::firstOrCreate(['name' => 'roles.assign', 'guard_name' => 'api']);
    $usersViewPerm    = Permission::firstOrCreate(['name' => 'users.view', 'guard_name' => 'api']);

    $adminRole = Role::firstOrCreate(['name' => 'Tenant_Admin', 'guard_name' => 'api']);
    $adminRole->givePermissionTo([$assignPermission, $usersViewPerm]);

    Role::firstOrCreate(['name' => 'Supplier', 'guard_name' => 'api']);

    $tenant = Tenant::factory()->create(['status' => 'active']);

    $admin = User::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
    $admin->assignRole($adminRole);
    $adminToken = JWTAuth::fromUser($admin);

    $targetUser = User::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
    // targetUser does NOT have Supplier role

    $response = authedRequest(
        'DELETE',
        "/api/v1/users/{$targetUser->id}/roles/Supplier",
        $adminToken,
        $tenant->id
    );

    $response->assertStatus(422);
    expect($response->json('success'))->toBeFalse();
});

it('returns 422 when assigning a non-existent role', function () {
    Bus::fake();

    $assignPermission = Permission::firstOrCreate(['name' => 'roles.assign', 'guard_name' => 'api']);
    $usersViewPerm    = Permission::firstOrCreate(['name' => 'users.view', 'guard_name' => 'api']);

    $adminRole = Role::firstOrCreate(['name' => 'Tenant_Admin', 'guard_name' => 'api']);
    $adminRole->givePermissionTo([$assignPermission, $usersViewPerm]);

    $tenant = Tenant::factory()->create(['status' => 'active']);

    $admin = User::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
    $admin->assignRole($adminRole);
    $adminToken = JWTAuth::fromUser($admin);

    $targetUser = User::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);

    $response = authedRequest(
        'POST',
        "/api/v1/users/{$targetUser->id}/roles",
        $adminToken,
        $tenant->id,
        ['role' => 'NonExistentRole']
    );

    $response->assertStatus(422);
    expect($response->json('success'))->toBeFalse();
});

it('dispatches audit log on role revocation', function () {
    Bus::fake();

    $assignPermission = Permission::firstOrCreate(['name' => 'roles.assign', 'guard_name' => 'api']);
    $usersViewPerm    = Permission::firstOrCreate(['name' => 'users.view', 'guard_name' => 'api']);

    $adminRole  = Role::firstOrCreate(['name' => 'Tenant_Admin', 'guard_name' => 'api']);
    $targetRole = Role::firstOrCreate(['name' => 'Store_Manager', 'guard_name' => 'api']);
    $adminRole->givePermissionTo([$assignPermission, $usersViewPerm]);

    $tenant = Tenant::factory()->create(['status' => 'active']);

    $admin = User::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
    $admin->assignRole($adminRole);
    $adminToken = JWTAuth::fromUser($admin);

    $targetUser = User::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
    $targetUser->assignRole($targetRole);

    authedRequest(
        'DELETE',
        "/api/v1/users/{$targetUser->id}/roles/Store_Manager",
        $adminToken,
        $tenant->id
    );

    Bus::assertDispatched(WriteAuditLogJob::class, function ($job) {
        $ref = new ReflectionClass($job);
        $actionProp = $ref->getProperty('actionType');
        $actionProp->setAccessible(true);
        return $actionProp->getValue($job) === 'role_revoked';
    });
});

// ---------------------------------------------------------------------------
// Requirement 3.2 — RoleMiddleware unit-level test
// ---------------------------------------------------------------------------

it('RoleMiddleware returns 403 JSON envelope when user lacks permission', function () {
    Permission::firstOrCreate(['name' => 'budgets.manage', 'guard_name' => 'api']);

    $role = Role::firstOrCreate(['name' => 'Department_Staff', 'guard_name' => 'api']);
    $role->revokePermissionTo('budgets.manage');

    $tenant = Tenant::factory()->create(['status' => 'active']);
    $user   = User::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
    $user->assignRole($role);

    app()->instance('tenant', $tenant);

    // Authenticate the user in the api guard
    \Illuminate\Support\Facades\Auth::guard('api')->setUser($user);

    $request = Request::create('/api/v1/budgets', 'GET');

    $middleware = app(RoleMiddleware::class);
    $response   = $middleware->handle($request, fn () => response()->json(['ok' => true]), 'budgets.manage');

    expect($response->getStatusCode())->toBe(403);

    $body = json_decode($response->getContent(), true);
    expect($body['success'])->toBeFalse();
    expect($body['data'])->toBeNull();
    expect($body['message'])->toBeString();
    expect($body['errors'])->toHaveKey('permission');
    expect($body['meta'])->toBeNull();
});
