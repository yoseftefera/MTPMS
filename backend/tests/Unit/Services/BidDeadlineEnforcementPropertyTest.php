<?php

/**
 * Property-Based Tests for BidService — Bid Deadline Enforcement.
 *
 * Property 9 — Deadline Enforcement Invariant (100 random timestamp pairs):
 *   For any tender deadline D and submission timestamp T (simulated via
 *   setting submission_deadline relative to now()):
 *   - T < D  → bid submission SUCCEEDS (returns a Bid with status 'submitted')
 *   - T ≥ D  → bid submission is REJECTED (throws InvalidArgumentException
 *               containing the word "deadline")
 *
 * The 100 pairs are drawn from five representative buckets to ensure
 * thorough coverage:
 *   - "well before"   (T is days before D)
 *   - "just before"   (T is 1–59 seconds before D)
 *   - "exactly equal" (D == now(), so T ≥ D is immediately satisfied)
 *   - "just after"    (D is 1–59 seconds in the past)
 *   - "well after"    (D is days in the past)
 *
 * Each iteration uses a fresh tenant + supplier to avoid unique-constraint
 * collisions and cross-iteration interference.
 *
 * **Validates: Requirements 8.4, 8.6, 21.8**
 *
 * @group property-based
 */

use App\Models\Supplier;
use App\Models\Tender;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BidService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

/**
 * Create a tenant, set it as the active tenant, and return it.
 */
function makeTenantForDeadlineTest(): Tenant
{
    $tenant = Tenant::factory()->create(['status' => 'active', 'tenant_code' => strtoupper(Str::random(4))]);
    app()->instance('tenant', $tenant);
    return $tenant;
}

/**
 * Create a Procurement_Officer user for the given tenant.
 */
function makeOfficerForDeadlineTest(Tenant $tenant): User
{
    $user = User::factory()->forTenant($tenant)->create(['status' => 'active']);
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Procurement_Officer', 'guard_name' => 'api']);
    $user->assignRole('Procurement_Officer');
    return $user;
}

/**
 * Create an active supplier (with an associated user) for the given tenant.
 *
 * @return array{supplier: Supplier, user: User}
 */
function makeActiveSupplierForDeadlineTest(Tenant $tenant): array
{
    $supplierUser = User::factory()->forTenant($tenant)->create(['status' => 'active']);
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Supplier', 'guard_name' => 'api']);
    $supplierUser->assignRole('Supplier');

    $supplier = Supplier::factory()->forTenant($tenant)->create([
        'user_id' => $supplierUser->id,
        'status'  => 'active',
    ]);

    return ['supplier' => $supplier, 'user' => $supplierUser];
}

/**
 * Create a published tender whose submission_deadline is set to $deadline.
 *
 * The reference_number is randomised to avoid unique-constraint violations
 * when many tenders are created across 100 iterations.
 */
function makeTenderWithDeadline(Tenant $tenant, User $officer, Carbon $deadline): Tender
{
    $refNumber = 'TND-' . now()->year . '-' . strtoupper(Str::random(8));

    $tender = new Tender();
    $tender->forceFill([
        'id'                  => (string) Str::uuid(),
        'tenant_id'           => $tenant->id,
        'reference_number'    => $refNumber,
        'title'               => 'Property Test Tender',
        'description'         => 'Deadline enforcement property test.',
        'category'            => 'IT Equipment',
        'tender_type'         => 'open',
        'estimated_value'     => '100000.00',
        'submission_deadline' => $deadline,
        'status'              => 'published',
        'created_by'          => $officer->id,
        'published_at'        => now()->subDays(3),
        'cancellation_reason' => null,
    ]);
    $tender->save();

    return $tender;
}

/**
 * Build a minimal bid data array for submission.
 */
function bidData(): array
{
    return [
        'total_amount'    => number_format(mt_rand(10_000, 500_000), 2, '.', ''),
        'currency'        => 'USD',
        'delivery_days'   => mt_rand(7, 90),
        'technical_notes' => null,
    ];
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
// Property 9A — T < D: bid submission succeeds (50 iterations)
// Validates: Requirements 8.4, 8.6, 21.8
// ---------------------------------------------------------------------------

it('Property 9A: bid submission succeeds for any T < D across 50 random "before deadline" timestamp pairs', function () {

    $service = new BidService();

    // Buckets for "T < D" — deadline is always in the FUTURE relative to now()
    // Each bucket represents a different proximity to the deadline.
    $buckets = [
        // well before: 1 day to 365 days ahead
        fn () => now()->addSeconds(mt_rand(86_400, 365 * 86_400)),
        // just before: 1 second to 59 seconds ahead
        fn () => now()->addSeconds(mt_rand(1, 59)),
        // just before: 1 minute to 23 hours ahead
        fn () => now()->addSeconds(mt_rand(60, 82_800)),
        // moderate: hours to days ahead
        fn () => now()->addSeconds(mt_rand(3_600, 86_400)),
        // far future: 1 year to 5 years ahead
        fn () => now()->addSeconds(mt_rand(365 * 86_400, 5 * 365 * 86_400)),
    ];

    $bucketCount = count($buckets);

    for ($iteration = 0; $iteration < 50; $iteration++) {
        // Pick a deadline from one of the "before" buckets (round-robin for even coverage)
        $deadline = $buckets[$iteration % $bucketCount]();

        $tenant   = makeTenantForDeadlineTest();
        $officer  = makeOfficerForDeadlineTest($tenant);
        $actorMap = makeActiveSupplierForDeadlineTest($tenant);

        /** @var Supplier $supplier */
        $supplier     = $actorMap['supplier'];
        $supplierUser = $actorMap['user'];

        $tender = makeTenderWithDeadline($tenant, $officer, $deadline);

        $threwException = false;
        $bid            = null;

        try {
            $bid = $service->submit(
                tender:   $tender,
                supplier: $supplier,
                data:     bidData(),
                actor:    $supplierUser,
            );
        } catch (\InvalidArgumentException $e) {
            $threwException = true;
        }

        expect($threwException)->toBeFalse(
            "Property 9A — Iteration {$iteration}: "
            . "Bid submission should SUCCEED when deadline is in the future "
            . "(deadline={$deadline->toIso8601String()}, now=" . now()->toIso8601String() . "). "
            . "BidService incorrectly rejected a bid before the deadline."
        );

        expect($bid)->not->toBeNull(
            "Property 9A — Iteration {$iteration}: submit() must return a Bid object when T < D."
        );

        expect($bid->status)->toBe(
            'submitted',
            "Property 9A — Iteration {$iteration}: Bid status should be 'submitted', got '{$bid->status}'."
        );

        expect($bid->submitted_at)->not->toBeNull(
            "Property 9A — Iteration {$iteration}: submitted_at must be set on a successful bid."
        );
    }

    expect(true)->toBeTrue('Property 9A: all 50 T<D iterations completed successfully.');
});

// ---------------------------------------------------------------------------
// Property 9B — T ≥ D: bid submission is rejected (50 iterations)
// Validates: Requirements 8.4, 8.6, 21.8
// ---------------------------------------------------------------------------

it('Property 9B: bid submission is rejected for any T >= D across 50 random "at or after deadline" timestamp pairs', function () {

    $service = new BidService();

    // Buckets for "T ≥ D" — deadline is in the PAST or exactly now.
    // We simulate T = now() ≥ D by placing deadline in the past (or equal to now - 1s).
    $buckets = [
        // exactly at deadline: deadline = 1 second ago (T > D)
        fn () => now()->subSecond(),
        // just after: 1 to 59 seconds in the past
        fn () => now()->subSeconds(mt_rand(1, 59)),
        // minutes after: 1 minute to 23 hours in the past
        fn () => now()->subSeconds(mt_rand(60, 82_800)),
        // well after: 1 day to 365 days in the past
        fn () => now()->subSeconds(mt_rand(86_400, 365 * 86_400)),
        // far past: 1 year to 5 years ago
        fn () => now()->subSeconds(mt_rand(365 * 86_400, 5 * 365 * 86_400)),
    ];

    $bucketCount = count($buckets);

    for ($iteration = 0; $iteration < 50; $iteration++) {
        // Pick a deadline from one of the "past/expired" buckets
        $deadline = $buckets[$iteration % $bucketCount]();

        $tenant   = makeTenantForDeadlineTest();
        $officer  = makeOfficerForDeadlineTest($tenant);
        $actorMap = makeActiveSupplierForDeadlineTest($tenant);

        /** @var Supplier $supplier */
        $supplier     = $actorMap['supplier'];
        $supplierUser = $actorMap['user'];

        // Note: the tender must have status = 'published' even though the
        // deadline is past, because the scheduler hasn't run yet. This mirrors
        // real-world conditions where the cron may not have closed the tender
        // before a late submission attempt is made.
        $tender = makeTenderWithDeadline($tenant, $officer, $deadline);

        $threwException   = false;
        $exceptionMessage = '';

        try {
            $service->submit(
                tender:   $tender,
                supplier: $supplier,
                data:     bidData(),
                actor:    $supplierUser,
            );
        } catch (\InvalidArgumentException $e) {
            $threwException   = true;
            $exceptionMessage = $e->getMessage();
        }

        expect($threwException)->toBeTrue(
            "Property 9B — Iteration {$iteration}: "
            . "Bid submission should be REJECTED when deadline has passed "
            . "(deadline={$deadline->toIso8601String()}, now=" . now()->toIso8601String() . "). "
            . "BidService accepted a bid after the deadline."
        );

        // The rejection message must reference the deadline so callers can
        // surface a meaningful error to the supplier (Req 8.4).
        expect(str_contains(strtolower($exceptionMessage), 'deadline'))->toBeTrue(
            "Property 9B — Iteration {$iteration}: "
            . "Rejection message must mention 'deadline'. Got: '{$exceptionMessage}'."
        );
    }

    expect(true)->toBeTrue('Property 9B: all 50 T≥D iterations completed successfully.');
});

// ---------------------------------------------------------------------------
// Property 9C — Exact-boundary: T = D is rejected (10 additional boundary checks)
// Validates: Requirements 8.4, 8.6
// ---------------------------------------------------------------------------

it('Property 9C: bid submission is rejected when submission timestamp equals the deadline (exact boundary T = D)', function () {

    $service = new BidService();

    // 10 boundary pairs: deadline = now() - 0s (i.e., deadline is not strictly
    // greater than now(), so now() >= deadline holds true).
    // We use subSecond() and subMicroseconds() variants to exercise the boundary.
    for ($iteration = 0; $iteration < 10; $iteration++) {
        $tenant   = makeTenantForDeadlineTest();
        $officer  = makeOfficerForDeadlineTest($tenant);
        $actorMap = makeActiveSupplierForDeadlineTest($tenant);

        /** @var Supplier $supplier */
        $supplier     = $actorMap['supplier'];
        $supplierUser = $actorMap['user'];

        // Deadline just crossed: 0–2 seconds in the past (boundary zone)
        $deadline = now()->subSeconds($iteration % 3); // 0, 1, or 2 seconds ago

        $tender = makeTenderWithDeadline($tenant, $officer, $deadline);

        $threwException = false;

        try {
            $service->submit(
                tender:   $tender,
                supplier: $supplier,
                data:     bidData(),
                actor:    $supplierUser,
            );
        } catch (\InvalidArgumentException $e) {
            $threwException = true;
        }

        expect($threwException)->toBeTrue(
            "Property 9C — Boundary iteration {$iteration}: "
            . "Bid submission must be REJECTED when deadline = {$deadline->toIso8601String()} "
            . "(T ≥ D boundary). now()=" . now()->toIso8601String() . "."
        );
    }

    expect(true)->toBeTrue('Property 9C: all 10 exact-boundary iterations confirmed T=D is rejected.');
});

// ---------------------------------------------------------------------------
// Property 9D — Deadline extension: after extending, T < new_D succeeds
// Validates: Requirements 8.6, 21.8
// ---------------------------------------------------------------------------

it('Property 9D: after a deadline extension, bid submission succeeds for timestamps before the new deadline across 10 iterations', function () {

    $service = new BidService();

    for ($iteration = 0; $iteration < 10; $iteration++) {
        $tenant   = makeTenantForDeadlineTest();
        $officer  = makeOfficerForDeadlineTest($tenant);
        $actorMap = makeActiveSupplierForDeadlineTest($tenant);

        /** @var Supplier $supplier */
        $supplier     = $actorMap['supplier'];
        $supplierUser = $actorMap['user'];

        // Original deadline is in the past (expired)
        $originalDeadline = now()->subMinutes(mt_rand(1, 60));

        $tender = makeTenderWithDeadline($tenant, $officer, $originalDeadline);

        // Verify original deadline → rejected
        $threwOnExpired = false;
        try {
            $service->submit(
                tender:   $tender,
                supplier: $supplier,
                data:     bidData(),
                actor:    $supplierUser,
            );
        } catch (\InvalidArgumentException $e) {
            $threwOnExpired = true;
        }

        expect($threwOnExpired)->toBeTrue(
            "Property 9D — Iteration {$iteration}: Pre-extension submission must be rejected."
        );

        // Extend the deadline to the future (simulate extendDeadline() DB update)
        $newDeadline = now()->addDays(mt_rand(1, 30));
        $tender->update(['submission_deadline' => $newDeadline]);
        $tender->refresh();

        // Create a new supplier to avoid the duplicate-bid constraint
        $actorMap2    = makeActiveSupplierForDeadlineTest($tenant);
        $supplier2    = $actorMap2['supplier'];
        $supplierUser2 = $actorMap2['user'];

        // After extension, submission with T < new_D must succeed
        $bid            = null;
        $threwOnExtended = false;

        try {
            $bid = $service->submit(
                tender:   $tender,
                supplier: $supplier2,
                data:     bidData(),
                actor:    $supplierUser2,
            );
        } catch (\InvalidArgumentException $e) {
            $threwOnExtended = true;
        }

        expect($threwOnExtended)->toBeFalse(
            "Property 9D — Iteration {$iteration}: After deadline extension to {$newDeadline->toIso8601String()}, "
            . "bid submission should SUCCEED (T < new_D)."
        );

        expect($bid)->not->toBeNull();
        expect($bid->status)->toBe('submitted');
    }

    expect(true)->toBeTrue('Property 9D: all 10 post-extension iterations completed successfully.');
});
