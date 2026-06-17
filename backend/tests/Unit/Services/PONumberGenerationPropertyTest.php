<?php

/**
 * Property-Based Tests for PurchaseOrderService::generatePONumber().
 *
 * Property 2 applied to POs — Uniqueness and Format (100 sequential generations):
 *   - Test A: 100 sequential generations for one tenant produce unique PO numbers
 *             matching PO-{CODE}-{YEAR}-{SEQ} pattern; sequence increments 00001→00100
 *   - Test B: Cross-tenant isolation — two tenants interleaved (50 each) produce
 *             disjoint sets; each tenant's own sequence is monotonically increasing
 *             from 00001 and all numbers are prefixed by their respective tenant code
 *
 * The sequence counter is driven by the row count in `purchase_orders` for the
 * tenant+year combination, so each iteration inserts a minimal PO row directly
 * to advance the counter without running the full generate() pipeline
 * (which requires budget, supplier, department, etc.).
 *
 * **Validates: Requirements 10.1, 21.8**
 *
 * @group property-based
 */

use App\Models\Department;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BudgetService;
use App\Services\PurchaseOrderService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

/**
 * Instantiate PurchaseOrderService with its required dependency.
 */
function makePOService(): PurchaseOrderService
{
    return new PurchaseOrderService(new BudgetService());
}

/**
 * Create an active tenant with a deterministic code and register it in the
 * service container so tenant-scoped queries resolve correctly.
 *
 * @return array{tenant: Tenant, supplier: Supplier, department: Department, user: User}
 */
function makeTenantWithFixturesForPOTest(string $tenantCode): array
{
    $tenant = Tenant::factory()->create([
        'status'      => 'active',
        'tenant_code' => strtoupper($tenantCode),
    ]);
    app()->instance('tenant', $tenant);

    $department = Department::factory()->forTenant($tenant)->create(['status' => 'active']);
    $user       = User::factory()->forTenant($tenant)->create(['status' => 'active']);
    $supplier   = Supplier::factory()->forTenant($tenant)->create(['status' => 'active']);

    return [
        'tenant'     => $tenant,
        'supplier'   => $supplier,
        'department' => $department,
        'user'       => $user,
    ];
}

/**
 * Insert a minimal purchase_orders row directly so the sequence counter
 * advances for subsequent generatePONumber() calls without going through
 * the full generate() pipeline (which requires items, budget, supplier, etc.).
 *
 * The `created_at` year must match the year used in generatePONumber() so
 * the WHERE YEAR('created_at') = $year clause picks it up correctly.
 */
function insertMinimalPO(string $tenantId, string $supplierId, string $departmentId, string $createdBy, string $poNumber): void
{
    DB::table('purchase_orders')->insert([
        'id'                     => (string) Str::uuid(),
        'tenant_id'              => $tenantId,
        'po_number'              => $poNumber,
        'supplier_id'            => $supplierId,
        'department_id'          => $departmentId,
        'created_by'             => $createdBy,
        'status'                 => 'draft',
        'total_amount'           => '0.00',
        'currency'               => 'USD',
        'delivery_address'       => 'Test Address',
        'required_delivery_date' => now()->addDays(30)->toDateString(),
        'created_at'             => now(),
        'updated_at'             => now(),
    ]);
}

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

beforeEach(function () {
    Bus::fake();

    Redis::shouldReceive('exists')->andReturn(0)->byDefault();
    Redis::shouldReceive('setex')->andReturn(true)->byDefault();
    Redis::shouldReceive('get')->andReturn(null)->byDefault();
    Redis::shouldReceive('del')->andReturn(1)->byDefault();
    Redis::shouldReceive('ttl')->andReturn(1800)->byDefault();
    Redis::shouldReceive('keys')->andReturn([])->byDefault();
});

// ---------------------------------------------------------------------------
// Test A — Sequential uniqueness within one tenant (100 iterations)
// Validates: Requirements 10.1, 21.8
// ---------------------------------------------------------------------------

it('Property 2A (PO): 100 sequential PO numbers for one tenant are unique, correctly formatted, and increment from 00001 to 00100', function () {
    $fixtures = makeTenantWithFixturesForPOTest('ACME');
    $tenant   = $fixtures['tenant'];
    $service  = makePOService();
    $numbers  = [];
    $year     = now()->year;

    for ($i = 1; $i <= 100; $i++) {
        $number    = $service->generatePONumber($tenant->tenant_code);
        $numbers[] = $number;

        // Insert a row so the counter increments for the next call
        insertMinimalPO(
            $tenant->id,
            $fixtures['supplier']->id,
            $fixtures['department']->id,
            $fixtures['user']->id,
            $number
        );
    }

    // --- Uniqueness: all 100 numbers must be distinct ---
    expect(count(array_unique($numbers)))->toBe(
        100,
        'All 100 generated PO numbers must be unique — collision detected.'
    );

    // --- Format: each number must match PO-[A-Z0-9]+-YYYY-NNNNN ---
    $pattern = '/^PO-[A-Z0-9]+-\d{4}-\d{5}$/';
    foreach ($numbers as $idx => $number) {
        $iteration = $idx + 1;
        expect((bool) preg_match($pattern, $number))->toBeTrue(
            "Iteration {$iteration}: number '{$number}' does not match expected format PO-[A-Z0-9]+-YYYY-NNNNN."
        );
    }

    // --- Year: YEAR part must equal the current year for all numbers ---
    foreach ($numbers as $idx => $number) {
        $iteration = $idx + 1;
        // Format: PO-ACME-YYYY-NNNNN → parts[2] is year
        $parts    = explode('-', $number);
        $yearPart = $parts[2];
        expect($yearPart)->toBe(
            (string) $year,
            "Iteration {$iteration}: YEAR part '{$yearPart}' should be '{$year}' in number '{$number}'."
        );
    }

    // --- Sequence: must increment monotonically 00001 → 00100 ---
    foreach ($numbers as $idx => $number) {
        $iteration   = $idx + 1;
        $parts       = explode('-', $number);
        $seqPart     = end($parts);
        $expectedSeq = str_pad((string) $iteration, 5, '0', STR_PAD_LEFT);
        expect($seqPart)->toBe(
            $expectedSeq,
            "Iteration {$iteration}: SEQUENCE part '{$seqPart}' should be '{$expectedSeq}' in number '{$number}'."
        );
    }

    expect(true)->toBeTrue('Property 2A (PO): all 100 sequential uniqueness assertions passed.');
});

// ---------------------------------------------------------------------------
// Test B — Cross-tenant isolation, no collisions (50 per tenant, interleaved)
// Validates: Requirements 10.1, 21.8
// ---------------------------------------------------------------------------

it('Property 2B (PO): cross-tenant isolation holds — two interleaved tenants (50 each) produce disjoint sets with monotonically increasing independent sequences', function () {
    $fixturesA = makeTenantWithFixturesForPOTest('TENA');
    $tenantA   = $fixturesA['tenant'];

    $tenantB = Tenant::factory()->create(['status' => 'active', 'tenant_code' => 'TENB']);
    app()->instance('tenant', $tenantB);
    $departmentB = Department::factory()->forTenant($tenantB)->create(['status' => 'active']);
    $userB       = User::factory()->forTenant($tenantB)->create(['status' => 'active']);
    $supplierB   = Supplier::factory()->forTenant($tenantB)->create(['status' => 'active']);

    $service  = makePOService();
    $numbersA = [];
    $numbersB = [];

    // Alternate: 50 for each tenant, interleaved
    for ($i = 1; $i <= 50; $i++) {
        // --- Tenant A ---
        app()->instance('tenant', $tenantA);
        $numberA    = $service->generatePONumber($tenantA->tenant_code);
        $numbersA[] = $numberA;
        insertMinimalPO($tenantA->id, $fixturesA['supplier']->id, $fixturesA['department']->id, $fixturesA['user']->id, $numberA);

        // --- Tenant B ---
        app()->instance('tenant', $tenantB);
        $numberB    = $service->generatePONumber($tenantB->tenant_code);
        $numbersB[] = $numberB;
        insertMinimalPO($tenantB->id, $supplierB->id, $departmentB->id, $userB->id, $numberB);
    }

    // --- Tenant A prefix assertion ---
    foreach ($numbersA as $idx => $number) {
        $iteration = $idx + 1;
        expect(str_starts_with($number, 'PO-TENA-'))->toBeTrue(
            "Iteration {$iteration}: tenant A number '{$number}' must start with 'PO-TENA-'."
        );
    }

    // --- Tenant B prefix assertion ---
    foreach ($numbersB as $idx => $number) {
        $iteration = $idx + 1;
        expect(str_starts_with($number, 'PO-TENB-'))->toBeTrue(
            "Iteration {$iteration}: tenant B number '{$number}' must start with 'PO-TENB-'."
        );
    }

    // --- Disjoint sets: zero cross-tenant collisions ---
    $intersection = array_intersect($numbersA, $numbersB);
    expect(count($intersection))->toBe(
        0,
        'Tenant A and tenant B PO number sets must be fully disjoint — cross-tenant collision detected: '
        . implode(', ', $intersection)
    );

    // --- Tenant A: monotonically increasing sequence from 00001 ---
    $pattern = '/^PO-[A-Z0-9]+-\d{4}-\d{5}$/';

    foreach ($numbersA as $idx => $number) {
        $iteration   = $idx + 1;
        $parts       = explode('-', $number);
        $seqPart     = end($parts);
        $expectedSeq = str_pad((string) $iteration, 5, '0', STR_PAD_LEFT);

        expect((bool) preg_match($pattern, $number))->toBeTrue(
            "Iteration {$iteration}: tenant A number '{$number}' does not match expected format."
        );
        expect($seqPart)->toBe(
            $expectedSeq,
            "Iteration {$iteration}: tenant A SEQUENCE '{$seqPart}' should be '{$expectedSeq}' in '{$number}'."
        );
    }

    // --- Tenant B: monotonically increasing sequence from 00001 ---
    foreach ($numbersB as $idx => $number) {
        $iteration   = $idx + 1;
        $parts       = explode('-', $number);
        $seqPart     = end($parts);
        $expectedSeq = str_pad((string) $iteration, 5, '0', STR_PAD_LEFT);

        expect((bool) preg_match($pattern, $number))->toBeTrue(
            "Iteration {$iteration}: tenant B number '{$number}' does not match expected format."
        );
        expect($seqPart)->toBe(
            $expectedSeq,
            "Iteration {$iteration}: tenant B SEQUENCE '{$seqPart}' should be '{$expectedSeq}' in '{$number}'."
        );
    }

    expect(true)->toBeTrue('Property 2B (PO): all cross-tenant isolation assertions passed.');
});
