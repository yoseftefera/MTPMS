<?php

/**
 * Property-Based Tests for BidEvaluationService — Weighted Scoring & Weight Validation.
 *
 * Property 6 — Weighted Score Invariant (100 random score/weight combinations):
 *   For any valid N criteria (N ∈ [1..5]) whose weights sum to exactly 100 and
 *   scores in range [0..100]:
 *   - calculateWeightedScore() == Σ(avg_score_for_criteria × weight / 100)
 *   - The result is a string with exactly 2 decimal places
 *   - The result is in range [0.00, 100.00]
 *   - Expected value is calculated using bcmath (no floating-point arithmetic)
 *   - Tolerance: 0.01 for bcmath vs number_format rounding comparison
 *
 * Property 7 — Weight Validation Invariant (100 random invalid weight configs):
 *   For any set of criteria whose weights do NOT sum to exactly 100:
 *   - configureCriteria() ALWAYS throws InvalidArgumentException
 *   Includes: empty array, single criterion ≠ 100, sums < 100, sums > 100, etc.
 *   Also verifies: when weights DO sum to 100, no exception is thrown (10 iterations).
 *
 * Each iteration creates a fresh tenant + officer to prevent cross-iteration
 * contamination from global scopes.
 *
 * **Validates: Requirements 9.1, 9.3**
 *
 * @group property-based
 */

use App\Models\Bid;
use App\Models\BidEvaluation;
use App\Models\BidEvaluationCriteria;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\Tender;
use App\Models\User;
use App\Services\BidEvaluationService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

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
// Shared helpers
// ---------------------------------------------------------------------------

/**
 * Create a fresh tenant, set it as the active tenant, and return it.
 */
function makeTenantForEvalTest(): Tenant
{
    $tenant = Tenant::factory()->create([
        'status'      => 'active',
        'tenant_code' => strtoupper(Str::random(4)),
    ]);
    app()->instance('tenant', $tenant);
    return $tenant;
}

/**
 * Create a Procurement_Officer for the given tenant.
 */
function makeOfficerForEvalTest(Tenant $tenant): User
{
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Procurement_Officer', 'guard_name' => 'api']);
    $user = User::factory()->forTenant($tenant)->create(['status' => 'active']);
    $user->assignRole('Procurement_Officer');
    return $user;
}

/**
 * Create a Committee_Member evaluator for the given tenant.
 */
function makeEvaluatorForEvalTest(Tenant $tenant): User
{
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Committee_Member', 'guard_name' => 'api']);
    $user = User::factory()->forTenant($tenant)->create(['status' => 'active']);
    $user->assignRole('Committee_Member');
    return $user;
}

/**
 * Create an active Supplier + linked user for the given tenant.
 *
 * @return array{supplier: Supplier, user: User}
 */
function makeSupplierForEvalTest(Tenant $tenant): array
{
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Supplier', 'guard_name' => 'api']);
    $supplierUser = User::factory()->forTenant($tenant)->create(['status' => 'active']);
    $supplierUser->assignRole('Supplier');

    $supplier = Supplier::factory()->forTenant($tenant)->create([
        'user_id' => $supplierUser->id,
        'status'  => 'active',
    ]);

    return ['supplier' => $supplier, 'user' => $supplierUser];
}

/**
 * Create a closed tender with assigned evaluators.
 *
 * Uses forceFill() to bypass the global tenant scope during construction,
 * matching the pattern used in BidDeadlineEnforcementPropertyTest.
 *
 * @param  string[]  $evaluatorIds
 */
function makeTenderForEvalTest(Tenant $tenant, User $officer, array $evaluatorIds): Tender
{
    $refNumber = 'TND-' . now()->year . '-' . strtoupper(Str::random(8));

    $tender = new Tender();
    $tender->forceFill([
        'id'                  => (string) Str::uuid(),
        'tenant_id'           => $tenant->id,
        'reference_number'    => $refNumber,
        'title'               => 'Property Test Tender',
        'description'         => 'Bid evaluation property test.',
        'category'            => 'IT Equipment',
        'tender_type'         => 'open',
        'estimated_value'     => '500000.00',
        'submission_deadline' => now()->subDays(2),
        'status'              => 'closed',
        'evaluation_mode'     => 'weighted',
        'assigned_evaluators' => $evaluatorIds,
        'created_by'          => $officer->id,
        'published_at'        => now()->subDays(30),
        'cancellation_reason' => null,
    ]);
    $tender->save();

    return $tender;
}

/**
 * Create a submitted bid for the given tender and supplier.
 */
function makeSubmittedBid(Tenant $tenant, Tender $tender, Supplier $supplier): Bid
{
    $bid = new Bid();
    $bid->forceFill([
        'id'           => (string) Str::uuid(),
        'tenant_id'    => $tenant->id,
        'tender_id'    => $tender->id,
        'supplier_id'  => $supplier->id,
        'total_amount' => number_format(mt_rand(10_000, 500_000), 2, '.', ''),
        'currency'     => 'USD',
        'delivery_days'=> mt_rand(7, 90),
        'status'       => 'submitted',
        'submitted_at' => now()->subDays(3),
    ]);
    $bid->save();

    return $bid;
}

/**
 * Generate N random integer weights that sum to exactly 100.
 *
 * Uses the "stick-breaking" method: split [0, 100] at N-1 random points,
 * then take the differences. This guarantees the sum is exactly 100 with
 * integer weights (each weight ≥ 1).
 *
 * @param  int  $n  Number of criteria (1–5)
 * @return int[]
 */
function generateWeightsThatSumTo100(int $n): array
{
    if ($n === 1) {
        return [100];
    }

    // Pick N-1 random split points in [1, 99], then sort and compute deltas
    $points = [];
    for ($i = 0; $i < $n - 1; $i++) {
        $points[] = mt_rand(1, 99);
    }
    sort($points);

    $weights = [];
    $prev    = 0;
    foreach ($points as $point) {
        $weights[] = $point - $prev;
        $prev       = $point;
    }
    $weights[] = 100 - $prev;

    // Ensure no zero weights (retry if any are zero)
    if (in_array(0, $weights, true)) {
        return generateWeightsThatSumTo100($n);
    }

    return $weights;
}

/**
 * Compute the expected weighted score using bcmath arithmetic (no floats).
 *
 * Formula: Σ( avg_score_for_criteria × weight / 100 )
 *
 * @param  array<int, array{weight: int, scores: int[]}>  $criteriaData
 * @return string  Result formatted to 2 decimal places
 */
function computeExpectedWeightedScore(array $criteriaData): string
{
    $scale = 10; // intermediate precision
    $total = '0';

    foreach ($criteriaData as $criterion) {
        $weight = (string) $criterion['weight'];
        $scores = $criterion['scores'];

        // Calculate average score using bcmath
        $sum   = '0';
        $count = (string) count($scores);

        foreach ($scores as $score) {
            $sum = bcadd($sum, (string) $score, $scale);
        }

        $avgScore = bcdiv($sum, $count, $scale);

        // Weighted contribution: avg_score × weight / 100
        $contribution = bcdiv(
            bcmul($avgScore, $weight, $scale),
            '100',
            $scale
        );

        $total = bcadd($total, $contribution, $scale);
    }

    return number_format((float) $total, 2, '.', '');
}

// ===========================================================================
// Property 6 — Weighted Score Invariant (100 iterations)
// Validates: Requirements 9.1, 9.3
// ===========================================================================

it(
    'Property 6: calculateWeightedScore() equals Σ(avg_score × weight / 100) with DECIMAL precision across 100 random score/weight combinations',
    function () {

        $service = new BidEvaluationService();

        for ($iteration = 0; $iteration < 100; $iteration++) {
            // --- Generate random configuration ---
            $n = mt_rand(1, 5); // number of criteria

            $weights = generateWeightsThatSumTo100($n);

            // One evaluator per iteration for simplicity (avg = single score)
            $tenant    = makeTenantForEvalTest();
            $officer   = makeOfficerForEvalTest($tenant);
            $evaluator = makeEvaluatorForEvalTest($tenant);

            $tender = makeTenderForEvalTest($tenant, $officer, [$evaluator->id]);

            // Create supplier + bid
            $supplierData = makeSupplierForEvalTest($tenant);
            $bid          = makeSubmittedBid($tenant, $tender, $supplierData['supplier']);

            // --- Configure criteria ---
            $criteriaConfig = [];
            for ($i = 0; $i < $n; $i++) {
                $criteriaConfig[] = [
                    'name'   => "Criterion {$i}",
                    'weight' => $weights[$i],
                ];
            }

            $service->configureCriteria(
                tender:   $tender,
                criteria: $criteriaConfig,
                actor:    $officer,
            );

            // Fetch created criteria
            $criteriaRecords = BidEvaluationCriteria::withoutGlobalScopes()
                ->where('tender_id', $tender->id)
                ->get();

            // --- Submit scores and build expected-value data ---
            $criteriaData = [];

            foreach ($criteriaRecords as $criterion) {
                $score = mt_rand(0, 100);

                $service->submitScore(
                    criteria:  $criterion,
                    bid:       $bid,
                    score:     $score,
                    evaluator: $evaluator,
                    actor:     $evaluator,
                );

                $criteriaData[] = [
                    'weight' => (int) $criterion->weight, // stored as DECIMAL, cast to int since weights are integers
                    'scores' => [$score],
                ];
            }

            // Refresh tender to ensure assigned_evaluators is up to date
            $tender->refresh();

            // --- Calculate expected and actual ---
            $expected = computeExpectedWeightedScore($criteriaData);
            $actual   = $service->calculateWeightedScore($bid);

            // Assert 1: result is a string
            expect($actual)->toBeString(
                "Property 6 — Iteration {$iteration}: calculateWeightedScore() must return a string, got " . gettype($actual)
            );

            // Assert 2: result has exactly 2 decimal places
            expect(preg_match('/^\d+\.\d{2}$/', $actual))->toBe(
                1,
                "Property 6 — Iteration {$iteration}: result must have exactly 2 decimal places, got '{$actual}'"
            );

            // Assert 3: result is in range [0.00, 100.00]
            $actualFloat = (float) $actual;
            expect($actualFloat)->toBeGreaterThanOrEqual(
                0.0,
                "Property 6 — Iteration {$iteration}: result must be ≥ 0.00, got '{$actual}'"
            );
            expect($actualFloat)->toBeLessThanOrEqual(
                100.0,
                "Property 6 — Iteration {$iteration}: result must be ≤ 100.00, got '{$actual}'"
            );

            // Assert 4: result matches expected value (within 0.01 tolerance for number_format rounding)
            $diff = abs((float) $actual - (float) $expected);

            // Build a flat scores summary for the error message
            $scoresSummary = implode(',', array_map(
                fn ($c) => implode('/', $c['scores']),
                $criteriaData
            ));

            expect($diff)->toBeLessThanOrEqual(
                0.01,
                "Property 6 — Iteration {$iteration}: "
                . "calculateWeightedScore() returned '{$actual}' "
                . "but expected '{$expected}' "
                . "(weights=" . implode(',', $weights) . ", "
                . "scores={$scoresSummary})."
            );
        }

        expect(true)->toBeTrue('Property 6: all 100 weighted score invariant iterations completed.');
    }
);

// ===========================================================================
// Property 6B — Multi-evaluator variant (additional 10 iterations with 2 evaluators)
// Validates: Requirements 9.3
// ===========================================================================

it(
    'Property 6B: calculateWeightedScore() is correct with multiple evaluators (avg of scores) across 10 additional iterations',
    function () {

        $service = new BidEvaluationService();

        for ($iteration = 0; $iteration < 10; $iteration++) {
            $n       = mt_rand(1, 3);
            $weights = generateWeightsThatSumTo100($n);

            $tenant     = makeTenantForEvalTest();
            $officer    = makeOfficerForEvalTest($tenant);
            $evaluator1 = makeEvaluatorForEvalTest($tenant);
            $evaluator2 = makeEvaluatorForEvalTest($tenant);

            $tender = makeTenderForEvalTest($tenant, $officer, [$evaluator1->id, $evaluator2->id]);

            $supplierData = makeSupplierForEvalTest($tenant);
            $bid          = makeSubmittedBid($tenant, $tender, $supplierData['supplier']);

            // Configure N criteria
            $criteriaConfig = [];
            for ($i = 0; $i < $n; $i++) {
                $criteriaConfig[] = [
                    'name'   => "Criterion {$i}",
                    'weight' => $weights[$i],
                ];
            }

            $service->configureCriteria(
                tender:   $tender,
                criteria: $criteriaConfig,
                actor:    $officer,
            );

            $criteriaRecords = BidEvaluationCriteria::withoutGlobalScopes()
                ->where('tender_id', $tender->id)
                ->get();

            $criteriaData = [];

            foreach ($criteriaRecords as $criterion) {
                $score1 = mt_rand(0, 100);
                $score2 = mt_rand(0, 100);

                $service->submitScore(
                    criteria:  $criterion,
                    bid:       $bid,
                    score:     $score1,
                    evaluator: $evaluator1,
                    actor:     $evaluator1,
                );

                $service->submitScore(
                    criteria:  $criterion,
                    bid:       $bid,
                    score:     $score2,
                    evaluator: $evaluator2,
                    actor:     $evaluator2,
                );

                $criteriaData[] = [
                    'weight' => (int) $criterion->weight,
                    'scores' => [$score1, $score2],
                ];
            }

            $tender->refresh();

            $expected = computeExpectedWeightedScore($criteriaData);
            $actual   = $service->calculateWeightedScore($bid);

            expect($actual)->toBeString(
                "Property 6B — Iteration {$iteration}: result must be a string."
            );

            $diff = abs((float) $actual - (float) $expected);
            expect($diff)->toBeLessThanOrEqual(
                0.01,
                "Property 6B — Iteration {$iteration}: "
                . "calculateWeightedScore() returned '{$actual}' but expected '{$expected}'."
            );
        }

        expect(true)->toBeTrue('Property 6B: all 10 multi-evaluator iterations completed.');
    }
);

// ===========================================================================
// Property 7 — Weight Validation Invariant (100 random invalid + 10 valid)
// Validates: Requirements 9.1
// ===========================================================================

it(
    'Property 7: configureCriteria() throws InvalidArgumentException for any weight set that does NOT sum to 100, across 100 random invalid configurations',
    function () {

        $service = new BidEvaluationService();

        // Buckets for generating invalid weight configurations
        $invalidBuckets = [
            // Single criterion weight ≠ 100
            function (): array {
                $w = mt_rand(1, 99); // 1..99 (never 100)
                return [['name' => 'A', 'weight' => $w]];
            },
            // Single criterion weight > 100
            function (): array {
                $w = mt_rand(101, 200);
                return [['name' => 'A', 'weight' => $w]];
            },
            // Two criteria summing to < 100
            function (): array {
                $w1 = mt_rand(1, 49);
                $w2 = mt_rand(1, 49);
                // Ensure sum is never 100
                while ($w1 + $w2 === 100) {
                    $w2 = mt_rand(1, 49);
                }
                return [
                    ['name' => 'A', 'weight' => $w1],
                    ['name' => 'B', 'weight' => $w2],
                ];
            },
            // Two criteria summing to > 100
            function (): array {
                $w1 = mt_rand(51, 99);
                $w2 = mt_rand(51, 99);
                return [
                    ['name' => 'A', 'weight' => $w1],
                    ['name' => 'B', 'weight' => $w2],
                ];
            },
            // Three criteria summing to 99 (off-by-one)
            function (): array {
                return [
                    ['name' => 'A', 'weight' => 33],
                    ['name' => 'B', 'weight' => 33],
                    ['name' => 'C', 'weight' => 33], // 33+33+33 = 99
                ];
            },
            // Three criteria summing to 101 (off-by-one)
            function (): array {
                return [
                    ['name' => 'A', 'weight' => 34],
                    ['name' => 'B', 'weight' => 34],
                    ['name' => 'C', 'weight' => 33], // 34+34+33 = 101
                ];
            },
            // All-zero weights
            function (): array {
                $n = mt_rand(1, 3);
                $result = [];
                for ($i = 0; $i < $n; $i++) {
                    $result[] = ['name' => "C{$i}", 'weight' => 0];
                }
                return $result;
            },
            // Large random sum that is not 100
            function (): array {
                $n = mt_rand(2, 5);
                $result = [];
                $sum = 0;
                for ($i = 0; $i < $n; $i++) {
                    $w = mt_rand(5, 30);
                    $result[] = ['name' => "C{$i}", 'weight' => $w];
                    $sum += $w;
                }
                // If it accidentally sums to 100, adjust the last weight
                if ($sum === 100) {
                    $result[0]['weight'] += 1; // Now sum = 101
                }
                return $result;
            },
        ];

        $bucketCount = count($invalidBuckets);

        for ($iteration = 0; $iteration < 100; $iteration++) {
            $tenant  = makeTenantForEvalTest();
            $officer = makeOfficerForEvalTest($tenant);
            $tender  = makeTenderForEvalTest($tenant, $officer, []);

            // Pick invalid config from buckets in round-robin order
            $criteria = $invalidBuckets[$iteration % $bucketCount]();

            $threwException = false;
            $actualSum      = array_sum(array_column($criteria, 'weight'));

            try {
                $service->configureCriteria(
                    tender:   $tender,
                    criteria: $criteria,
                    actor:    $officer,
                );
            } catch (InvalidArgumentException $e) {
                $threwException = true;
            }

            expect($threwException)->toBeTrue(
                "Property 7 — Iteration {$iteration}: "
                . "configureCriteria() must throw InvalidArgumentException when weights sum to {$actualSum} (not 100). "
                . "Criteria: " . json_encode($criteria)
            );
        }

        expect(true)->toBeTrue('Property 7: all 100 invalid-weight iterations confirmed rejection.');
    }
);

// ===========================================================================
// Property 7B — Empty array is also rejected
// Validates: Requirements 9.1
// ===========================================================================

it(
    'Property 7B: configureCriteria() throws InvalidArgumentException for an empty criteria array',
    function () {

        $service = new BidEvaluationService();
        $tenant  = makeTenantForEvalTest();
        $officer = makeOfficerForEvalTest($tenant);
        $tender  = makeTenderForEvalTest($tenant, $officer, []);

        $threwException = false;

        try {
            $service->configureCriteria(
                tender:   $tender,
                criteria: [],
                actor:    $officer,
            );
        } catch (InvalidArgumentException $e) {
            $threwException = true;
        }

        expect($threwException)->toBeTrue(
            'Property 7B: configureCriteria() must throw InvalidArgumentException for an empty criteria array.'
        );
    }
);

// ===========================================================================
// Property 7C — Valid configurations are accepted (10 iterations)
// Validates: Requirements 9.1
// ===========================================================================

it(
    'Property 7C: configureCriteria() does NOT throw when weights sum to exactly 100 across 10 random valid configurations',
    function () {

        $service = new BidEvaluationService();

        for ($iteration = 0; $iteration < 10; $iteration++) {
            $n       = mt_rand(1, 5);
            $weights = generateWeightsThatSumTo100($n);

            $tenant  = makeTenantForEvalTest();
            $officer = makeOfficerForEvalTest($tenant);
            $tender  = makeTenderForEvalTest($tenant, $officer, []);

            $criteriaConfig = [];
            for ($i = 0; $i < $n; $i++) {
                $criteriaConfig[] = [
                    'name'   => "Criterion {$i}",
                    'weight' => $weights[$i],
                ];
            }

            $threwException = false;

            try {
                $service->configureCriteria(
                    tender:   $tender,
                    criteria: $criteriaConfig,
                    actor:    $officer,
                );
            } catch (InvalidArgumentException $e) {
                $threwException = true;
            }

            expect($threwException)->toBeFalse(
                "Property 7C — Iteration {$iteration}: "
                . "configureCriteria() must NOT throw when weights sum to exactly 100 "
                . "(weights=" . implode(',', $weights) . ", sum=" . array_sum($weights) . ")."
            );

            // Verify the criteria were actually persisted
            $count = BidEvaluationCriteria::withoutGlobalScopes()
                ->where('tender_id', $tender->id)
                ->count();

            expect($count)->toBe(
                $n,
                "Property 7C — Iteration {$iteration}: "
                . "Expected {$n} criteria records in the database, got {$count}."
            );
        }

        expect(true)->toBeTrue('Property 7C: all 10 valid-weight iterations confirmed acceptance.');
    }
);
