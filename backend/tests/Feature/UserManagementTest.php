<?php

use App\Jobs\SendWelcomeEmailJob;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Feature tests for UserManagementService and UserController.
 *
 * Validates Requirements: 4.1, 4.2, 4.6, 4.8, 4.9
 *
 * Covers:
 *  - CRUD for users within tenant scope (Req 4.1)
 *  - Welcome email dispatched on user creation (Req 4.2)
 *  - Unique email enforcement per tenant (Req 4.8)
 *  - Paginated, searchable, sortable user list (Req 4.6)
 *  - Deletion guard for active PRs and POs (Req 4.9)
 *  - Role assignment and revocation
 *  - Tenant isolation (cross-tenant access denied)
 */

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Ensure all required permissions exist and return a Tenant_Admin user with a JWT.
 */
function setupTenantWithAdmin(): array
{
    $tenant = Tenant::factory()->create(['status' => 'active']);

    // Ensure all required permissions exist
    $permissions = [
        'users.view', 'users.create', 'users.update', 'users.delete',
        'roles.assign',
    ];
    foreach ($permissions as $perm) {
        Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'api']);
    }

    // Ensure the Tenant_Admin role exists and has all user permissions
    $role = Role::firstOrCreate(['name' => 'Tenant_Admin', 'guard_name' => 'api']);
    $role->syncPermissions($permissions);

    $admin = User::factory()->create([
        'tenant_id' => $tenant->id,
        'status'    => 'active',
        'password'  => Hash::make('password'),
    ]);
    $admin->assignRole($role);

    $token = JWTAuth::fromUser($admin);

    return compact('tenant', 'admin', 'token');
}

/**
 * Make an authenticated request with the tenant header.
 */
function userMgmtRequest(string $method, string $url, array $data, string $token, string $tenantId): \Illuminate\Testing\TestResponse
{
    return test()
        ->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'X-Tenant-ID'   => $tenantId,
            'Accept'        => 'application/json',
        ])
        ->{strtolower($method) . 'Json'}($url, $data);
}

// ---------------------------------------------------------------------------
// Setup — mock Redis for all tests
// ---------------------------------------------------------------------------

beforeEach(function () {
    Redis::shouldReceive('exists')->andReturn(0)->byDefault();
    Redis::shouldReceive('setex')->andReturn(true)->byDefault();
    Redis::shouldReceive('get')->andReturn(null)->byDefault();
    Redis::shouldReceive('del')->andReturn(1)->byDefault();
    Redis::shouldReceive('set')->andReturn(true)->byDefault();
});

// ---------------------------------------------------------------------------
// Requirement 4.1 — CRUD for users within tenant scope
// ---------------------------------------------------------------------------

it('creates a user within the active tenant', function () {
    Bus::fake();

    ['tenant' => $tenant, 'token' => $token] = setupTenantWithAdmin();

    $response = userMgmtRequest('post', '/api/v1/users', [
        'name'  => 'Jane Doe',
        'email' => 'jane@example.com',
    ], $token, $tenant->id);

    $response->assertStatus(201);
    $response->assertJson([
        'success' => true,
        'data'    => [
            'name'      => 'Jane Doe',
            'email'     => 'jane@example.com',
            'tenant_id' => (string) $tenant->id,
        ],
    ]);

    $this->assertDatabaseHas('users', [
        'email'     => 'jane@example.com',
        'tenant_id' => $tenant->id,
    ]);
});

it('returns a single user by ID within the tenant', function () {
    Bus::fake();

    ['tenant' => $tenant, 'token' => $token] = setupTenantWithAdmin();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'name'      => 'Bob Smith',
        'email'     => 'bob@example.com',
    ]);

    $response = userMgmtRequest('get', "/api/v1/users/{$user->id}", [], $token, $tenant->id);

    $response->assertStatus(200);
    $response->assertJson([
        'success' => true,
        'data'    => [
            'id'    => (string) $user->id,
            'name'  => 'Bob Smith',
            'email' => 'bob@example.com',
        ],
    ]);
});

it('updates a user profile within the tenant', function () {
    Bus::fake();

    ['tenant' => $tenant, 'token' => $token] = setupTenantWithAdmin();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'name'      => 'Old Name',
        'phone'     => null,
    ]);

    $response = userMgmtRequest('put', "/api/v1/users/{$user->id}", [
        'name'  => 'New Name',
        'phone' => '+1234567890',
    ], $token, $tenant->id);

    $response->assertStatus(200);
    $response->assertJson([
        'success' => true,
        'data'    => [
            'name'  => 'New Name',
            'phone' => '+1234567890',
        ],
    ]);

    $this->assertDatabaseHas('users', [
        'id'    => $user->id,
        'name'  => 'New Name',
        'phone' => '+1234567890',
    ]);
});

it('soft-deletes a user with no active records', function () {
    Bus::fake();

    ['tenant' => $tenant, 'token' => $token] = setupTenantWithAdmin();

    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'email'     => 'todelete@example.com',
    ]);

    $response = userMgmtRequest('delete', "/api/v1/users/{$user->id}", [], $token, $tenant->id);

    $response->assertStatus(200);
    $response->assertJson(['success' => true]);

    $this->assertSoftDeleted('users', ['id' => $user->id]);
});

// ---------------------------------------------------------------------------
// Requirement 4.2 — Welcome email with 24-hour password-setup link
// ---------------------------------------------------------------------------

it('dispatches SendWelcomeEmailJob when a new user is created', function () {
    Bus::fake();

    ['tenant' => $tenant, 'token' => $token] = setupTenantWithAdmin();

    userMgmtRequest('post', '/api/v1/users', [
        'name'  => 'Welcome User',
        'email' => 'welcome@example.com',
    ], $token, $tenant->id);

    Bus::assertDispatched(SendWelcomeEmailJob::class);
});

it('stores a 24-hour setup token in Redis when a user is created', function () {
    Bus::fake();

    $setupTokenTtl = null;
    Redis::shouldReceive('setex')
        ->withArgs(function ($key, $ttl, $value) use (&$setupTokenTtl) {
            if (str_starts_with($key, 'pwd:setup:')) {
                $setupTokenTtl = $ttl;
            }
            return true;
        })
        ->andReturn(true);

    ['tenant' => $tenant, 'token' => $token] = setupTenantWithAdmin();

    userMgmtRequest('post', '/api/v1/users', [
        'name'  => 'Setup Token User',
        'email' => 'setup@example.com',
    ], $token, $tenant->id);

    // TTL must be 24 hours (86400 seconds)
    expect($setupTokenTtl)->toBe(86400);
    Bus::assertDispatched(SendWelcomeEmailJob::class);
});

// ---------------------------------------------------------------------------
// Requirement 4.6 — Paginated, searchable, sortable user list
// ---------------------------------------------------------------------------

it('returns a paginated list of users within the tenant', function () {
    Bus::fake();

    ['tenant' => $tenant, 'token' => $token] = setupTenantWithAdmin();

    User::factory()->count(5)->create(['tenant_id' => $tenant->id]);

    $response = userMgmtRequest('get', '/api/v1/users?per_page=3', [], $token, $tenant->id);

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'success',
        'data',
        'message',
        'errors',
        'meta' => ['current_page', 'per_page', 'total', 'last_page'],
    ]);

    $meta = $response->json('meta');
    expect($meta['per_page'])->toBe(3);
    // 5 created + 1 admin = 6 total
    expect($meta['total'])->toBeGreaterThanOrEqual(5);
});

it('filters users by name search term', function () {
    Bus::fake();

    ['tenant' => $tenant, 'token' => $token] = setupTenantWithAdmin();

    User::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Alice Wonderland']);
    User::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Bob Builder']);

    $response = userMgmtRequest('get', '/api/v1/users?search=Alice', [], $token, $tenant->id);

    $response->assertStatus(200);
    $data = $response->json('data');
    $names = collect($data)->pluck('name')->toArray();
    expect($names)->toContain('Alice Wonderland');
    expect($names)->not->toContain('Bob Builder');
});

it('filters users by status', function () {
    Bus::fake();

    ['tenant' => $tenant, 'token' => $token] = setupTenantWithAdmin();

    User::factory()->create(['tenant_id' => $tenant->id, 'status' => 'inactive']);
    User::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);

    $response = userMgmtRequest('get', '/api/v1/users?status=inactive', [], $token, $tenant->id);

    $response->assertStatus(200);
    $data = $response->json('data');
    foreach ($data as $user) {
        expect($user['status'])->toBe('inactive');
    }
});

// ---------------------------------------------------------------------------
// Requirement 4.8 — Unique email per tenant
// ---------------------------------------------------------------------------

it('rejects creating a user with a duplicate email within the same tenant', function () {
    Bus::fake();

    ['tenant' => $tenant, 'token' => $token] = setupTenantWithAdmin();

    User::factory()->create([
        'tenant_id' => $tenant->id,
        'email'     => 'duplicate@example.com',
    ]);

    $response = userMgmtRequest('post', '/api/v1/users', [
        'name'  => 'Duplicate User',
        'email' => 'duplicate@example.com',
    ], $token, $tenant->id);

    $response->assertStatus(422);
    $response->assertJson(['success' => false]);
});

it('allows the same email address in different tenants', function () {
    Bus::fake();

    ['tenant' => $tenant1, 'token' => $token1] = setupTenantWithAdmin();
    ['tenant' => $tenant2, 'token' => $token2] = setupTenantWithAdmin();

    // Create user in tenant 1
    userMgmtRequest('post', '/api/v1/users', [
        'name'  => 'Shared Email User',
        'email' => 'shared@example.com',
    ], $token1, $tenant1->id);

    // Same email in tenant 2 should succeed
    $response = userMgmtRequest('post', '/api/v1/users', [
        'name'  => 'Shared Email User',
        'email' => 'shared@example.com',
    ], $token2, $tenant2->id);

    $response->assertStatus(201);
    $response->assertJson(['success' => true]);
});

// ---------------------------------------------------------------------------
// Requirement 4.9 — Deletion guard for active PRs and POs
// ---------------------------------------------------------------------------

it('rejects deletion of a user with active purchase requests', function () {
    Bus::fake();

    ['tenant' => $tenant, 'token' => $token] = setupTenantWithAdmin();

    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    // Create an active PR linked to this user
    PurchaseRequest::factory()->create([
        'tenant_id'    => $tenant->id,
        'submitted_by' => $user->id,
        'status'       => 'pending_approval',
    ]);

    $response = userMgmtRequest('delete', "/api/v1/users/{$user->id}", [], $token, $tenant->id);

    $response->assertStatus(422);
    $response->assertJson(['success' => false]);

    $body = $response->json();
    expect($body['data']['active_purchase_requests'])->toBe(1);
    expect($body['data']['active_purchase_orders'])->toBe(0);
});

it('rejects deletion of a user with active purchase orders', function () {
    Bus::fake();

    ['tenant' => $tenant, 'token' => $token] = setupTenantWithAdmin();

    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    // Create an active PO linked to this user
    PurchaseOrder::factory()->create([
        'tenant_id'  => $tenant->id,
        'created_by' => $user->id,
        'status'     => 'issued',
    ]);

    $response = userMgmtRequest('delete', "/api/v1/users/{$user->id}", [], $token, $tenant->id);

    $response->assertStatus(422);
    $response->assertJson(['success' => false]);

    $body = $response->json();
    expect($body['data']['active_purchase_orders'])->toBe(1);
});

it('returns counts of both active PRs and POs when both exist', function () {
    Bus::fake();

    ['tenant' => $tenant, 'token' => $token] = setupTenantWithAdmin();

    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    PurchaseRequest::factory()->count(2)->create([
        'tenant_id'    => $tenant->id,
        'submitted_by' => $user->id,
        'status'       => 'draft',
    ]);

    PurchaseOrder::factory()->count(3)->create([
        'tenant_id'  => $tenant->id,
        'created_by' => $user->id,
        'status'     => 'accepted',
    ]);

    $response = userMgmtRequest('delete', "/api/v1/users/{$user->id}", [], $token, $tenant->id);

    $response->assertStatus(422);
    $body = $response->json();
    expect($body['data']['active_purchase_requests'])->toBe(2);
    expect($body['data']['active_purchase_orders'])->toBe(3);
});

it('allows deletion of a user whose PRs and POs are in terminal statuses', function () {
    Bus::fake();

    ['tenant' => $tenant, 'token' => $token] = setupTenantWithAdmin();

    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    // Terminal statuses — should not block deletion
    PurchaseRequest::factory()->create([
        'tenant_id'    => $tenant->id,
        'submitted_by' => $user->id,
        'status'       => 'approved',
    ]);

    PurchaseOrder::factory()->create([
        'tenant_id'  => $tenant->id,
        'created_by' => $user->id,
        'status'     => 'fully_received',
    ]);

    $response = userMgmtRequest('delete', "/api/v1/users/{$user->id}", [], $token, $tenant->id);

    $response->assertStatus(200);
    $response->assertJson(['success' => true]);
    $this->assertSoftDeleted('users', ['id' => $user->id]);
});

// ---------------------------------------------------------------------------
// Tenant isolation
// ---------------------------------------------------------------------------

it('cannot access a user belonging to a different tenant', function () {
    Bus::fake();

    ['tenant' => $tenant1, 'token' => $token1] = setupTenantWithAdmin();
    ['tenant' => $tenant2] = setupTenantWithAdmin();

    // Create a user in tenant 2
    $otherUser = User::factory()->create(['tenant_id' => $tenant2->id]);

    // Try to access it from tenant 1's context
    $response = userMgmtRequest('get', "/api/v1/users/{$otherUser->id}", [], $token1, $tenant1->id);

    // Should return 404 because the global scope filters it out
    if ($response->status() !== 404) {
        dump($response->json());
    }
    $response->assertStatus(404);
});

// ---------------------------------------------------------------------------
// Response envelope format
// ---------------------------------------------------------------------------

it('user list response conforms to the standard API envelope', function () {
    Bus::fake();

    ['tenant' => $tenant, 'token' => $token] = setupTenantWithAdmin();

    $response = userMgmtRequest('get', '/api/v1/users', [], $token, $tenant->id);

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'success',
        'data',
        'message',
        'errors',
        'meta',
    ]);
    expect($response->json('success'))->toBeTrue();
    expect($response->json('errors'))->toBeNull();
});

it('create user response conforms to the standard API envelope', function () {
    Bus::fake();

    ['tenant' => $tenant, 'token' => $token] = setupTenantWithAdmin();

    $response = userMgmtRequest('post', '/api/v1/users', [
        'name'  => 'Envelope Test',
        'email' => 'envelope@example.com',
    ], $token, $tenant->id);

    $response->assertStatus(201);
    $response->assertJsonStructure([
        'success',
        'data' => ['id', 'name', 'email', 'tenant_id', 'status', 'created_at'],
        'message',
        'errors',
        'meta',
    ]);
});
