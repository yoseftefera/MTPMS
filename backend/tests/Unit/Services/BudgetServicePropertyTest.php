<?php

/**
 * Property-Based Tests for BudgetService.
 *
 * Property 3 — Budget Enforcement Invariant (100 iterations):
 *   For any random budget and PR value:
 *   - Case A: PR ≤ available balance → validatePRAgainstBudget() does NOT throw
 *   - Case B: PR > available balance → validatePRAgainstBudget() throws BudgetExceededException
 *     with correct available_balance and shortfall (PR value − available balance)
 *
 * Property 4 — Encumbrance Round-Trip (100 iterations):
 *   For any random budget and encumbrance amount ≤ available balance:
 *   - encumberAmount() decreases available balance by exactly that amount
 *   - releaseEncumbrance() restores available balance to exactly the original value
 *
 * **Validates: Requirements 13.2, 13.3, 13.4, 13.5, 21.6**
 */

use App\Exceptions\BudgetExceededException;
use App\Models\Budget;
use App\Models\Department;
use App\Models\PurchaseRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BudgetService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

/**
 * Generate a random monetary amount as a 2-decimal-place string.
 * Range: 1.00 – 100,000.00
 */
function randomAmount(int $minCents = 100, int $maxCents = 10_000_000): string
{
    return number_format(mt_rand($minCents, $maxCents) / 100, 2, '.', '');
}

/**
 * Compute available balance using BCMath to mirror BudgetService::computeAvailable().
 */
function computeAvailable(Budget $budget): string
{
    $total      = bcadd($budget->total_amount, '0', 2);
    $encumbered = bcadd($budget->encumbered_amount, '0', 2);
    $spent      = bcadd($budget->spent_amount, '0', 2);
    $committed  = bcadd($encumbered, $spent, 2);
    $available  = bcsub($total, $committed, 2);

    return bccomp($available, '0.00', 2) >= 0 ? $available : '0.00';
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
// Property 3 — Budget Enforcement Invariant
// Validates: Requirements 13.2, 13.3
// ---------------------------------------------------------------------------

it('Property 3: budget enforcement invariant holds across 100 random budget and PR combinations', function () {

    $tenant = Tenant::factory()->create(['status' => 'active']);
    app()->instance('tenant', $tenant);

    $department = Department::factory()->forTenant($tenant)->create(['status' => 'active']);
    $user       = User::factory()->forTenant($tenant)->create();

    $service = new BudgetService();

    for ($i = 0; $i < 100; $i++) {
        // Generate a random budget total (100.00 – 10000.00)
        $totalAmount = randomAmount(10_000, 1_000_000); // 100.00 – 10000.00

        // Random encumbered and spent amounts that sum to ≤ 80% of total
        $maxCommitted    = bcdiv(bcmul($totalAmount, '0.80', 2), '1', 2);
        $encumberedRaw   = randomAmount(0, (int) bcmul($maxCommitted, '50', 0));
        $encumberedAmount = bccomp($encumberedRaw, $maxCommitted, 2) > 0 ? $maxCommitted : $encumberedRaw;
        $remaining       = bcsub($maxCommitted, $encumberedAmount, 2);
        $spentRaw        = randomAmount(0, max(1, (int) bcmul($remaining, '50', 0)));
        $spentAmount     = bccomp($spentRaw, $remaining, 2) > 0 ? $remaining : $spentRaw;

        // Create fresh budget for this iteration using factory + forceFill for tenant_id
        $budget = Budget::factory()->forTenant($tenant)->forDepartment($department)->make([
            'fiscal_year'       => now()->year,
            'currency'          => 'USD',
            'total_amount'      => $totalAmount,
            'encumbered_amount' => $encumberedAmount,
            'spent_amount'      => $spentAmount,
            'created_by'        => $user->id,
        ]);
        $budget->save();

        $available = computeAvailable($budget);

        // ----------------------------------------------------------------
        // Case A: PR value ≤ available balance → must NOT throw
        // ----------------------------------------------------------------
        if (bccomp($available, '0.00', 2) > 0) {
            // Generate a PR value between 0.01 and available balance
            $prValueA = number_format(
                mt_rand(1, max(1, (int) bcmul($available, '100', 0))) / 100,
                2, '.', ''
            );
            // Clamp to available to be safe
            if (bccomp($prValueA, $available, 2) > 0) {
                $prValueA = $available;
            }

            $pr = new PurchaseRequest();
            $pr->forceFill([
                'id'              => (string) Str::uuid(),
                'tenant_id'       => $tenant->id,
                'department_id'   => $department->id,
                'pr_number'       => 'PR-TEST-' . now()->year . '-' . str_pad($i * 2 + 1, 5, '0', STR_PAD_LEFT),
                'submitted_by'    => $user->id,
                'status'          => 'draft',
                'title'           => 'Property Test PR (Case A)',
                'estimated_total' => $prValueA,
                'currency'        => 'USD',
            ]);
            // Bypass HasTenantScope so we can save without a real model event
            $pr->save();

            $threw = false;
            try {
                $service->validatePRAgainstBudget($pr);
            } catch (BudgetExceededException $e) {
                $threw = true;
            }

            expect($threw)->toBeFalse(
                "Iteration {$i}: Case A — PR value {$prValueA} ≤ available {$available} should NOT throw BudgetExceededException."
            );
        }

        // ----------------------------------------------------------------
        // Case B: PR value > available balance → must throw with correct data
        // ----------------------------------------------------------------
        // Generate a PR value strictly greater than available
        $excess   = randomAmount(1, 100_000);                    // 0.01 – 1000.00
        $prValueB = bcadd($available, $excess, 2);

        $pr2 = new PurchaseRequest();
        $pr2->forceFill([
            'id'              => (string) Str::uuid(),
            'tenant_id'       => $tenant->id,
            'department_id'   => $department->id,
            'pr_number'       => 'PR-TEST-' . now()->year . '-' . str_pad($i * 2 + 2, 5, '0', STR_PAD_LEFT),
            'submitted_by'    => $user->id,
            'status'          => 'draft',
            'title'           => 'Property Test PR (Case B)',
            'estimated_total' => $prValueB,
            'currency'        => 'USD',
        ]);
        $pr2->save();

        $exception = null;
        try {
            $service->validatePRAgainstBudget($pr2);
        } catch (BudgetExceededException $e) {
            $exception = $e;
        }

        expect($exception)->not->toBeNull(
            "Iteration {$i}: Case B — PR value {$prValueB} > available {$available} must throw BudgetExceededException."
        );

        if ($exception !== null) {
            $expectedShortfall = bcsub($prValueB, $available, 2);

            expect($exception->getAvailableBalance())->toBe(
                $available,
                "Iteration {$i}: available_balance in exception should be {$available}, got {$exception->getAvailableBalance()}."
            );

            expect($exception->getShortfall())->toBe(
                $expectedShortfall,
                "Iteration {$i}: shortfall should be {$expectedShortfall} (prValue={$prValueB} - available={$available}), got {$exception->getShortfall()}."
            );
        }

        // Clean up budget for next iteration (avoid unique constraint violations)
        $budget->delete();
    }

    // Confirm we ran 100 positive assertion cycles
    expect(true)->toBeTrue('Property 3: all 100 iterations completed successfully.');
});

// ---------------------------------------------------------------------------
// Property 4 — Encumbrance Round-Trip
// Validates: Requirements 13.4, 13.5
// ---------------------------------------------------------------------------

it('Property 4: encumbrance round-trip restores original balance across 100 random iterations', function () {

    $tenant = Tenant::factory()->create(['status' => 'active']);
    app()->instance('tenant', $tenant);

    $department = Department::factory()->forTenant($tenant)->create(['status' => 'active']);
    $user       = User::factory()->forTenant($tenant)->create();

    $service = new BudgetService();

    for ($i = 0; $i < 100; $i++) {
        // Random budget total (1000.00 – 50000.00)
        $totalAmount = randomAmount(100_000, 5_000_000); // 1000.00 – 50000.00

        // Small, fixed spent_amount so available balance is predictable
        $spentAmount     = randomAmount(100, 10_000); // 1.00 – 100.00
        $encumberedStart = '0.00';

        $budget = Budget::factory()->forTenant($tenant)->forDepartment($department)->make([
            'fiscal_year'       => now()->year,
            'currency'          => 'USD',
            'total_amount'      => $totalAmount,
            'encumbered_amount' => $encumberedStart,
            'spent_amount'      => $spentAmount,
            'created_by'        => $user->id,
        ]);
        $budget->save();

        $availableBefore = computeAvailable($budget);

        // Encumbrance amount must be ≤ available balance
        $maxEncCents     = max(1, (int) bcmul($availableBefore, '100', 0));
        $encAmountCents  = mt_rand(1, $maxEncCents);
        $encAmount       = number_format($encAmountCents / 100, 2, '.', '');

        // Reference UUID for the round-trip
        $referenceId = (string) Str::uuid();

        // ----------------------------------------------------------------
        // Step 1: Encumber the amount
        // ----------------------------------------------------------------
        $encResult = $service->encumberAmount(
            budget:        $budget,
            amount:        $encAmount,
            referenceType: 'purchase_order',
            referenceId:   $referenceId,
            actor:         $user,
        );

        expect($encResult['success'])->toBeTrue(
            "Iteration {$i}: encumberAmount() should succeed for amount={$encAmount}, available={$availableBefore}."
        );

        // Reload budget after encumbrance
        $budget->refresh();
        $availableAfterEnc = computeAvailable($budget);

        $expectedAfterEnc = bcsub($availableBefore, $encAmount, 2);

        expect($availableAfterEnc)->toBe(
            $expectedAfterEnc,
            "Iteration {$i}: after encumbering {$encAmount}, available balance should decrease from {$availableBefore} to {$expectedAfterEnc}, got {$availableAfterEnc}."
        );

        // ----------------------------------------------------------------
        // Step 2: Release the encumbrance (simulate PO cancellation)
        // ----------------------------------------------------------------
        $relResult = $service->releaseEncumbrance(
            budget:        $budget,
            amount:        $encAmount,
            referenceType: 'purchase_order',
            referenceId:   $referenceId,
            actor:         $user,
        );

        expect($relResult['success'])->toBeTrue(
            "Iteration {$i}: releaseEncumbrance() should succeed for amount={$encAmount}."
        );

        // Reload budget after release
        $budget->refresh();
        $availableAfterRelease = computeAvailable($budget);

        expect($availableAfterRelease)->toBe(
            $availableBefore,
            "Iteration {$i}: after releasing {$encAmount}, available balance should be restored to {$availableBefore}, got {$availableAfterRelease}."
        );

        // Clean up for next iteration
        $budget->delete();
    }

    expect(true)->toBeTrue('Property 4: all 100 round-trip iterations completed successfully.');
});
