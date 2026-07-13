<?php

/**
 * Property-Based Tests for AuditLog Immutability.
 *
 * Property 10 — Audit Log Immutability Invariant
 *
 * Two sub-properties are verified across 100 random iterations:
 *
 * Sub-property A — HTTP method rejection (100 random audit log rows):
 *   For any audit_log row and any caller role:
 *   - DELETE /api/v1/audit-logs/{id} MUST return HTTP 403
 *   - PUT    /api/v1/audit-logs/{id} MUST return HTTP 403
 *   - PATCH  /api/v1/audit-logs/{id} MUST return HTTP 403
 *   Roles rotated: System_Admin, Tenant_Admin, Procurement_Officer,
 *                  Finance_Officer, Store_Manager
 *
 * Sub-property B — Monotonically non-decreasing record count:
 *   After 100 random create/read/attempt-delete sequences, the total
 *   audit_log row count must equal the number of rows inserted (no
 *   deletes ever succeed).
 *
 * **Validates: Requirements 17.5, 17.6, 21.1**
 *
 * @group property-based
 */

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tymon\JWTAuth\Facades\JWTAuth;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

/**
 * Create a fresh tenant and register it as the active application tenant.
 */
function makeTenantForAuditTest(): Tenant
{
    $tenant = Tenant::factory()->create([
        'status'      => 'active',
        'tenant_code' => strtoupper(Str::random(4)),
    ]);
    app()->instance('tenant', $tenant);
    return $tenant;
}

/**
 * Create a user in the given tenant, assign the role, and return a real JWT.
 */
function makeUserWithRoleAndToken(Tenant $tenant, string $roleName): array
{
    $user = User::factory()->forTenant($tenant)->create(['status' => 'active']);

    Role::firstOrCreate([
        'name'       => $roleName,
        'guard_name' => 'api',
    ]);

    $user->assignRole($roleName);

    $token = JWTAuth::fromUser($user);

    return compact('user', 'token');
}

/**
 * Build a minimal audit_log row payload.
 * Rows are inserted directly via DB::table to bypass Eloquent hooks,
 * mirroring the append-only WriteAuditLogJob path.
 */
function makeAuditLogRow(Tenant $tenant, User $user, string $roleName, int $iteration = 0): array
{
    return [
        'id'          => (string) Str::uuid(),
        'tenant_id'   => $tenant->id,
        'user_id'     => $user->id,
        'user_role'   => $roleName,
        'action'      => 'test.property.' . strtolower(str_replace('_', '.', $roleName)),
        'entity_type' => 'property_test_entity',
        'entity_id'   => (string) Str::uuid(),
        'before_data' => null,
        'after_data'  => json_encode(['iteration' => $iteration]),
        'ip_address'  => '127.0.0.' . mt_rand(1, 254),
        'request_id'  => (string) Str::uuid(),
        'created_at'  => now()->toDateTimeString(),
    ];
}

/**
 * Fire an authenticated JSON request against the audit-log endpoint.
 */
function auditRequest(string $method, string $url, string $token, string $tenantId, array $body = []): \Illuminate\Testing\TestResponse
{
    return test()
        ->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID'   => $tenantId,
            'Accept'        => 'application/json',
        ])
        ->{strtolower($method) . 'Json'}($url, $body);
}

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

beforeEach(function () {
    Bus::fake();

    // Prevent real Redis connections during property tests.
    Redis::shouldReceive('exists')->andReturn(0)->byDefault();
    Redis::shouldReceive('setex')->andReturn(true)->byDefault();
    Redis::shouldReceive('get')->andReturn(null)->byDefault();
    Redis::shouldReceive('del')->andReturn(1)->byDefault();
    Redis::shouldReceive('ttl')->andReturn(1800)->byDefault();
    Redis::shouldReceive('keys')->andReturn([])->byDefault();
});

// ---------------------------------------------------------------------------
// Property 10 — Sub-property A: DELETE / PUT / PATCH always return 403
//               across all roles (100 random audit log rows).
// Validates: Requirements 17.5, 17.6
// ---------------------------------------------------------------------------

it('Property 10A: DELETE, PUT, and PATCH on any audit_log row return HTTP 403 regardless of caller role (100 iterations)', function () {

    // All five roles that may interact with audit logs.
    // They are rotated round-robin across 100 iterations so each role appears ~20 times.
    $roles = [
        'System_Admin',
        'Tenant_Admin',
        'Procurement_Officer',
        'Finance_Officer',
        'Store_Manager',
    ];
    $roleCount = count($roles);

    $insertedIds = [];

    for ($iteration = 0; $iteration < 100; $iteration++) {
        $roleName = $roles[$iteration % $roleCount];

        // Fresh tenant + user per iteration — no cross-contamination.
        $tenant = makeTenantForAuditTest();
        ['user' => $user, 'token' => $token] = makeUserWithRoleAndToken($tenant, $roleName);

        // Insert a row directly (append-only path).
        $row = makeAuditLogRow($tenant, $user, $roleName, $iteration);
        DB::table('audit_logs')->insert($row);
        $insertedIds[] = $row['id'];

        $rowId    = $row['id'];
        $tenantId = (string) $tenant->id;

        // -- DELETE /api/v1/audit-logs/{id} must return 403 ------------------
        $deleteResponse = auditRequest('delete', "/api/v1/audit-logs/{$rowId}", $token, $tenantId);

        expect($deleteResponse->status())->toBe(
            403,
            "Property 10A — Iteration {$iteration} (role={$roleName}): "
            . "DELETE /api/v1/audit-logs/{$rowId} must return HTTP 403. "
            . "Got HTTP {$deleteResponse->status()} instead."
        );

        // -- PUT /api/v1/audit-logs/{id} must return 403 ---------------------
        $putResponse = auditRequest('put', "/api/v1/audit-logs/{$rowId}", $token, $tenantId, [
            'action'      => 'tampered_action',
            'entity_type' => 'tampered_entity',
            'ip_address'  => '10.0.0.1',
        ]);

        expect($putResponse->status())->toBe(
            403,
            "Property 10A — Iteration {$iteration} (role={$roleName}): "
            . "PUT /api/v1/audit-logs/{$rowId} must return HTTP 403. "
            . "Got HTTP {$putResponse->status()} instead."
        );

        // -- PATCH /api/v1/audit-logs/{id} must return 403 -------------------
        $patchResponse = auditRequest('patch', "/api/v1/audit-logs/{$rowId}", $token, $tenantId, [
            'action' => 'tampered_via_patch',
        ]);

        expect($patchResponse->status())->toBe(
            403,
            "Property 10A — Iteration {$iteration} (role={$roleName}): "
            . "PATCH /api/v1/audit-logs/{$rowId} must return HTTP 403. "
            . "Got HTTP {$patchResponse->status()} instead."
        );
    }

    // -------------------------------------------------------------------------
    // Post-loop invariant: all 100 rows must still be present in the database.
    // Any successful DELETE would reduce this count below 100.
    // -------------------------------------------------------------------------
    $countAfter = DB::table('audit_logs')
        ->whereIn('id', $insertedIds)
        ->count();

    expect($countAfter)->toBe(
        100,
        "Property 10A — Post-loop invariant FAILED: "
        . "Expected all 100 inserted audit_log rows to remain after 100 DELETE attempts. "
        . "Found {$countAfter} rows. Some deletes may have succeeded."
    );

    // Spot-check: DELETE response must conform to standard API envelope.
    $lastTenant = makeTenantForAuditTest();
    ['user' => $lastUser, 'token' => $lastToken] = makeUserWithRoleAndToken($lastTenant, 'Finance_Officer');
    $lastRow = makeAuditLogRow($lastTenant, $lastUser, 'Finance_Officer', 999);
    DB::table('audit_logs')->insert($lastRow);

    $envelopeCheck = auditRequest(
        'delete',
        "/api/v1/audit-logs/{$lastRow['id']}",
        $lastToken,
        (string) $lastTenant->id
    );

    $envelopeCheck->assertStatus(403);
    $envelopeCheck->assertJsonStructure(['success', 'data', 'message', 'errors', 'meta']);
    expect($envelopeCheck->json('success'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Property 10 — Sub-property B: Total record count is monotonically
//               non-decreasing across 100 random create/read/attempt-delete
//               sequences.
// Validates: Requirements 17.5, 21.1
// ---------------------------------------------------------------------------

it('Property 10B: audit_log record count is monotonically non-decreasing across 100 random create/read/attempt-delete sequences', function () {

    $roles = [
        'Tenant_Admin',
        'Procurement_Officer',
        'Finance_Officer',
        'Store_Manager',
    ];
    $roleCount = count($roles);

    // Snapshot the count before any sequences start.
    $baselineCount = DB::table('audit_logs')->count();
    $previousCount = $baselineCount;
    $totalInserted = 0;

    // All inserted row IDs — for final invariant check.
    $insertedIds = [];

    // Each sequence randomly picks one of three operations:
    //  0 — create:         insert a new audit_log row directly (count +1)
    //  1 — read:           count rows (count unchanged)
    //  2 — attempt-delete: fire HTTP DELETE (must 403; count unchanged)
    //
    // The first 20 iterations always create rows so there are always targets
    // available for the attempt-delete path.

    for ($iteration = 0; $iteration < 100; $iteration++) {
        $roleName = $roles[$iteration % $roleCount];
        $tenant   = makeTenantForAuditTest();
        ['user' => $user, 'token' => $token] = makeUserWithRoleAndToken($tenant, $roleName);

        // Skew towards create for the first 20 iterations.
        $operation = ($iteration < 20) ? 0 : mt_rand(0, 2);

        switch ($operation) {

            // ----------------------------------------------------------------
            // Operation 0 — CREATE: insert a new row via DB::table
            // ----------------------------------------------------------------
            case 0:
                $row = makeAuditLogRow($tenant, $user, $roleName, $iteration);
                DB::table('audit_logs')->insert($row);
                $insertedIds[] = $row['id'];
                $totalInserted++;

                $countAfterCreate = DB::table('audit_logs')->count();

                // Count must be strictly non-decreasing.
                expect($countAfterCreate)->toBeGreaterThanOrEqual(
                    $previousCount,
                    "Property 10B — Iteration {$iteration} (CREATE): "
                    . "Count must be ≥ {$previousCount} after insert. Got {$countAfterCreate}."
                );

                // Count must exactly equal baseline + total inserted so far.
                expect($countAfterCreate)->toBe(
                    $baselineCount + $totalInserted,
                    "Property 10B — Iteration {$iteration} (CREATE): "
                    . "Count should equal baseline + totalInserted = "
                    . ($baselineCount + $totalInserted) . ". Got {$countAfterCreate}."
                );

                $previousCount = $countAfterCreate;
                break;

            // ----------------------------------------------------------------
            // Operation 1 — READ: verify count is stable
            // ----------------------------------------------------------------
            case 1:
                $countOnRead = DB::table('audit_logs')->count();

                expect($countOnRead)->toBe(
                    $previousCount,
                    "Property 10B — Iteration {$iteration} (READ): "
                    . "Count should still be {$previousCount} with no mutations. Got {$countOnRead}."
                );
                break;

            // ----------------------------------------------------------------
            // Operation 2 — ATTEMPT-DELETE: HTTP DELETE must return 403;
            //               count must not decrease.
            // ----------------------------------------------------------------
            case 2:
                // If no rows have been inserted yet, seed one first.
                if (empty($insertedIds)) {
                    $seedRow = makeAuditLogRow($tenant, $user, $roleName, $iteration);
                    DB::table('audit_logs')->insert($seedRow);
                    $insertedIds[] = $seedRow['id'];
                    $totalInserted++;
                    $previousCount = DB::table('audit_logs')->count();
                }

                // Pick a random target from all inserted rows.
                $targetId = $insertedIds[array_rand($insertedIds)];

                $deleteResponse = auditRequest(
                    'delete',
                    "/api/v1/audit-logs/{$targetId}",
                    $token,
                    (string) $tenant->id
                );

                // DELETE must always be rejected.
                expect($deleteResponse->status())->toBe(
                    403,
                    "Property 10B — Iteration {$iteration} (ATTEMPT-DELETE, role={$roleName}): "
                    . "DELETE /api/v1/audit-logs/{$targetId} must return HTTP 403. "
                    . "Got HTTP {$deleteResponse->status()}."
                );

                // Count must not have decreased.
                $countAfterAttempt = DB::table('audit_logs')->count();

                expect($countAfterAttempt)->toBeGreaterThanOrEqual(
                    $previousCount,
                    "Property 10B — Iteration {$iteration} (ATTEMPT-DELETE): "
                    . "Count must be ≥ {$previousCount} after a failed delete. "
                    . "Got {$countAfterAttempt}."
                );

                expect($countAfterAttempt)->toBe(
                    $previousCount,
                    "Property 10B — Iteration {$iteration} (ATTEMPT-DELETE): "
                    . "Count must remain exactly {$previousCount} after HTTP 403 delete. "
                    . "Got {$countAfterAttempt} — a delete may have succeeded."
                );

                break;
        }
    }

    // -------------------------------------------------------------------------
    // Final invariant: every row inserted during the sequences must still exist.
    // -------------------------------------------------------------------------
    if (!empty($insertedIds)) {
        $finalCount = DB::table('audit_logs')
            ->whereIn('id', $insertedIds)
            ->count();

        expect($finalCount)->toBe(
            $totalInserted,
            "Property 10B — Final invariant FAILED: "
            . "Expected all {$totalInserted} inserted rows to remain. "
            . "Found only {$finalCount}. Some deletes may have succeeded."
        );
    }

    expect(true)->toBeTrue('Property 10B: monotonic non-decreasing count invariant verified.');
});
