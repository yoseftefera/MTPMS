<?php

/**
 * Property-Based Tests for PurchaseRequestService::generatePRNumber().
 *
 * Property 2 — Uniqueness and Format (100 sequential generations):
 *   - Test A: 100 sequential generations for one tenant produce unique PR numbers
 *             matching PR-{CODE}-{YEAR}-{SEQ} pattern; sequence increments 00001→00100
 *   - Test B: Cross-tenant isolation — two tenants interleaved produce disjoint
 *             sets and each tenant's own sequence is monotonically increasing from 00001
 *   - Test C: Format holds across 10 random tenant codes (100 total numbers, 0 format violations)
 *
 * **Validates: Requirements 5.1, 21.8**
 */

use App\Models\Department;
use App\Models\Tenant;
use App\Models\User;
use App\Repositories\PurchaseRequestRepository;
use App\Services\BudgetService;
use App\Services\PurchaseRequestService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

/**
 * Resolve PurchaseRequestService from the container (uses bound interface).
 */
function makePRService(): PurchaseRequestService
{
    return new PurchaseRequestService(
        new PurchaseRequestRepository(),
        new BudgetService(),
    );
}

/**
 * Insert a minimal purchase_requests row directly so the sequence counter
 * advances for subsequent generatePRNumber() calls without going through
 * the full create() pipeline (which requires items, budget, etc.).
 */
function insertMinimalPR(string $tenantId, string $departmentId, string $submittedBy, string $prNumber): void
{
    DB::table('purchase_requests')->insert([
        'id'              => (string) Str::uuid(),
        'tenant_id'       => $tenantId,
        'pr_number'       => $prNumber,
        'department_id'   => $departmentId,
        'submitted_by'    => $submittedBy,
        'status'          => 'draft',
        'title'           => 'Property Test PR',
        'estimated_total' => '100.00',
        'currency'        => 'USD',
        'created_at'      => now(),
        'updated_at'      => now(),
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
// Validates: Requirements 5.1, 21.8
// ---------------------------------------------------------------------------

it('Property 2A: 100 sequential PR numbers for one tenant are unique, correctly formatted, and increment from 00001 to 00100', function () {
    $tenant = Tenant::factory()->create(['status' => 'active', 'tenant_code' => 'ACME']);
    app()->instance('tenant', $tenant);

    $department = Department::factory()->forTenant($tenant)->create(['status' => 'active']);
    $user       = User::factory()->forTenant($tenant)->create();

    $service = makePRService();
    $numbers = [];
    $year    = now()->year;

    for ($i = 1; $i <= 100; $i++) {
        $number = $service->generatePRNumber($tenant);
        $numbers[] = $number;

        // Insert a row so the counter increments for the next call
        insertMinimalPR($tenant->id, $department->id, $user->id, $number);
    }

    // All 100 numbers must be unique
    expect(count(array_unique($numbers)))->toBe(
        100,
        'All 100 generated PR numbers must be unique — collision detected.'
    );

    // Each number must match the expected format
    $pattern = '/^PR-[A-Z0-9]+-\d{4}-\d{5}$/';
    foreach ($numbers as $idx => $number) {
        $iteration = $idx + 1;
        expect((bool) preg_match($pattern, $number))->toBeTrue(
            "Iteration {$iteration}: number '{$number}' does not match expected format PR-[A-Z0-9]+-YYYY-NNNNN."
        );
    }

    // YEAR part must equal the current year for all numbers
    foreach ($numbers as $idx => $number) {
        $iteration = $idx + 1;
        $parts = explode('-', $number);
        // Format: PR-ACME-YYYY-NNNNN → parts[2] is year
        $yearPart = $parts[2];
        expect($yearPart)->toBe(
            (string) $year,
            "Iteration {$iteration}: YEAR part '{$yearPart}' should be '{$year}' in number '{$number}'."
        );
    }

    // SEQUENCE must increment from 00001 to 00100 in order
    foreach ($numbers as $idx => $number) {
        $iteration  = $idx + 1;
        $parts      = explode('-', $number);
        $seqPart    = end($parts);
        $expectedSeq = str_pad((string) $iteration, 5, '0', STR_PAD_LEFT);
        expect($seqPart)->toBe(
            $expectedSeq,
            "Iteration {$iteration}: SEQUENCE part '{$seqPart}' should be '{$expectedSeq}' in number '{$number}'."
        );
    }

    expect(true)->toBeTrue('Property 2A: all 100 sequential uniqueness assertions passed.');
});

// ---------------------------------------------------------------------------
// Test B — Cross-tenant isolation, no collisions (100 total interleaved)
// Validates: Requirements 5.1, 21.8
// ---------------------------------------------------------------------------

it('Property 2B: cross-tenant isolation holds — two interleaved tenants produce disjoint sets with monotonically increasing sequences', function () {
    $tenantA = Tenant::factory()->create(['status' => 'active', 'tenant_code' => 'TENA']);
    $tenantB = Tenant::factory()->create(['status' => 'active', 'tenant_code' => 'TENB']);

    // Use tenant A as the active tenant for model scoping (both tenants are fully created)
    app()->instance('tenant', $tenantA);

    $deptA = Department::factory()->forTenant($tenantA)->create(['status' => 'active']);
    $deptB = Department::factory()->forTenant($tenantB)->create(['status' => 'active']);
    $userA = User::factory()->forTenant($tenantA)->create();
    $userB = User::factory()->forTenant($tenantB)->create();

    $service  = makePRService();
    $numbersA = [];
    $numbersB = [];

    // Alternate: 50 for each tenant, interleaved
    for ($i = 1; $i <= 50; $i++) {
        app()->instance('tenant', $tenantA);
        $numberA = $service->generatePRNumber($tenantA);
        $numbersA[] = $numberA;
        insertMinimalPR($tenantA->id, $deptA->id, $userA->id, $numberA);

        app()->instance('tenant', $tenantB);
        $numberB = $service->generatePRNumber($tenantB);
        $numbersB[] = $numberB;
        insertMinimalPR($tenantB->id, $deptB->id, $userB->id, $numberB);
    }

    // Tenant A numbers must all contain '-TENA-'
    foreach ($numbersA as $idx => $number) {
        $iteration = $idx + 1;
        expect(str_contains($number, '-TENA-'))->toBeTrue(
            "Iteration {$iteration}: tenant A number '{$number}' must contain '-TENA-'."
        );
    }

    // Tenant B numbers must all contain '-TENB-'
    foreach ($numbersB as $idx => $number) {
        $iteration = $idx + 1;
        expect(str_contains($number, '-TENB-'))->toBeTrue(
            "Iteration {$iteration}: tenant B number '{$number}' must contain '-TENB-'."
        );
    }

    // The two sets must be fully disjoint (no cross-tenant collisions)
    $intersection = array_intersect($numbersA, $numbersB);
    expect(count($intersection))->toBe(
        0,
        'Tenant A and tenant B PR number sets must be fully disjoint — cross-tenant collision detected: '
        . implode(', ', $intersection)
    );

    // Each tenant's sequence starts at 00001 and is monotonically increasing
    $pattern = '/^PR-[A-Z0-9]+-\d{4}-\d{5}$/';

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

    expect(true)->toBeTrue('Property 2B: all cross-tenant isolation assertions passed.');
});

// ---------------------------------------------------------------------------
// Test C — Format holds across 10 random tenant codes (100 total numbers)
// Validates: Requirements 5.1, 21.8
// ---------------------------------------------------------------------------

it('Property 2C: format PR-{CODE}-{YEAR}-{SEQ} holds across 10 random tenant codes with 0 format violations', function () {
    $service       = makePRService();
    $pattern       = '/^PR-[A-Z0-9]+-\d{4}-\d{5}$/';
    $totalGenerated = 0;
    $violations    = [];

    $alphabet = 'ABCDEFGHIJKLMNOP';

    for ($t = 0; $t < 10; $t++) {
        // Generate a unique 4-6 character uppercase alpha tenant code
        $codeLength = mt_rand(4, 6);
        $shuffled   = str_split($alphabet);
        shuffle($shuffled);
        $tenantCode = strtoupper(implode('', array_slice($shuffled, 0, $codeLength)));

        $tenant = Tenant::factory()->create(['status' => 'active', 'tenant_code' => $tenantCode]);
        app()->instance('tenant', $tenant);

        $dept = Department::factory()->forTenant($tenant)->create(['status' => 'active']);
        $user = User::factory()->forTenant($tenant)->create();

        for ($i = 1; $i <= 10; $i++) {
            $number = $service->generatePRNumber($tenant);
            $totalGenerated++;

            if (! preg_match($pattern, $number)) {
                $violations[] = "Tenant {$tenantCode}, iteration {$i}: '{$number}' does not match format.";
            }

            insertMinimalPR($tenant->id, $dept->id, $user->id, $number);
        }
    }

    expect($totalGenerated)->toBe(
        100,
        "Expected exactly 100 PR numbers to be generated across 10 tenants, got {$totalGenerated}."
    );

    expect(count($violations))->toBe(
        0,
        'Format violations found (' . count($violations) . '): ' . implode(' | ', $violations)
    );

    expect(true)->toBeTrue('Property 2C: 0 format violations across all 100 generated PR numbers.');
});
