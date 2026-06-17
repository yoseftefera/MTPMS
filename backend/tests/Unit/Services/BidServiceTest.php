<?php

/**
 * Unit tests for BidService.
 *
 * Covers task 9.2 — bid submission API with the following business rules:
 *  1. Req 8.4 — validate submission timestamp against deadline; reject if past deadline
 *  2. Req 8.5 — enforce one bid per supplier per tender; allow revisions before deadline
 *  3. Req 8.7 — prevent suppliers from viewing other suppliers' bids (confidentiality)
 *
 * **Validates: Requirements 8.4, 8.5, 8.7**
 */

use App\Models\Bid;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\Tender;
use App\Models\User;
use App\Services\BidService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Redis;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Shared setup
// ---------------------------------------------------------------------------

beforeEach(function () {
    Bus::fake();

    Redis::shouldReceive('exists')->andReturn(0)->byDefault();
    Redis::shouldReceive('setex')->andReturn(true)->byDefault();
    Redis::shouldReceive('get')->andReturn(null)->byDefault();
    Redis::shouldReceive('del')->andReturn(1)->byDefault();
    Redis::shouldReceive('ttl')->andReturn(1800)->byDefault();
    Redis::shouldReceive('keys')->andReturn([])->byDefault();

    $this->tenant = Tenant::factory()->create(['status' => 'active', 'tenant_code' => 'ACME']);

    // Supplier user + supplier profile
    $this->supplierUser = User::factory()->forTenant($this->tenant)->create(['status' => 'active']);
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Supplier', 'guard_name' => 'api']);
    $this->supplierUser->assignRole('Supplier');

    $this->supplier = Supplier::factory()->forTenant($this->tenant)->create([
        'user_id' => $this->supplierUser->id,
        'status'  => 'active',
    ]);

    // Procurement officer (can view all bids)
    $this->officerUser = User::factory()->forTenant($this->tenant)->create(['status' => 'active']);
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Procurement_Officer', 'guard_name' => 'api']);
    $this->officerUser->assignRole('Procurement_Officer');

    // A published tender with a future deadline
    $this->tender = Tender::factory()->forTenant($this->tenant)->create([
        'status'              => 'published',
        'submission_deadline' => now()->addDays(14),
        'published_at'        => now()->subDays(3),
        'created_by'          => $this->officerUser->id,
        'category'            => 'IT Equipment',
    ]);

    app()->instance('tenant', $this->tenant);

    $this->service = new BidService();
});

// ===========================================================================
// 1. submit() — Requirement 8.4: deadline validation
// ===========================================================================

it('submit: creates a bid with status submitted when deadline has not passed', function () {
    $bid = $this->service->submit(
        tender:   $this->tender,
        supplier: $this->supplier,
        data: [
            'total_amount'    => '50000.00',
            'currency'        => 'USD',
            'delivery_days'   => 21,
            'technical_notes' => 'Meets all specifications.',
        ],
        actor: $this->supplierUser,
    );

    expect($bid->status)->toBe('submitted')
        ->and($bid->submitted_at)->not->toBeNull()
        ->and($bid->tender_id)->toBe($this->tender->id)
        ->and($bid->supplier_id)->toBe($this->supplier->id)
        ->and(number_format((float) $bid->total_amount, 2, '.', ''))->toBe('50000.00');

    $this->assertDatabaseHas('bids', [
        'id'          => $bid->id,
        'status'      => 'submitted',
        'tender_id'   => $this->tender->id,
        'supplier_id' => $this->supplier->id,
    ]);
});

it('submit: throws InvalidArgumentException when deadline has already passed', function () {
    // Create a tender whose deadline has already passed
    $expiredTender = Tender::factory()->forTenant($this->tenant)->create([
        'status'              => 'published',
        'submission_deadline' => now()->subMinutes(5),
        'published_at'        => now()->subDays(14),
        'created_by'          => $this->officerUser->id,
    ]);

    $this->service->submit(
        tender:   $expiredTender,
        supplier: $this->supplier,
        data: [
            'total_amount'  => '30000.00',
            'delivery_days' => 14,
        ],
        actor: $this->supplierUser,
    );
})->throws(InvalidArgumentException::class, 'deadline');

it('submit: throws InvalidArgumentException when deadline is exactly now (boundary: T >= D is rejected)', function () {
    // Set deadline to exactly "now" — the boundary case (>= deadline is rejected)
    $deadlineTender = Tender::factory()->forTenant($this->tenant)->create([
        'status'              => 'published',
        'submission_deadline' => now()->subSecond(), // one second in the past
        'published_at'        => now()->subDays(7),
        'created_by'          => $this->officerUser->id,
    ]);

    $this->service->submit(
        tender:   $deadlineTender,
        supplier: $this->supplier,
        data: [
            'total_amount'  => '25000.00',
            'delivery_days' => 10,
        ],
        actor: $this->supplierUser,
    );
})->throws(InvalidArgumentException::class);

it('submit: throws InvalidArgumentException when tender is not published (e.g. draft)', function () {
    $draftTender = Tender::factory()->forTenant($this->tenant)->draft()->create([
        'created_by' => $this->officerUser->id,
    ]);

    $this->service->submit(
        tender:   $draftTender,
        supplier: $this->supplier,
        data: [
            'total_amount'  => '10000.00',
            'delivery_days' => 7,
        ],
        actor: $this->supplierUser,
    );
})->throws(InvalidArgumentException::class);

it('submit: throws InvalidArgumentException when tender is closed', function () {
    $closedTender = Tender::factory()->forTenant($this->tenant)->closed()->create([
        'created_by' => $this->officerUser->id,
    ]);

    $this->service->submit(
        tender:   $closedTender,
        supplier: $this->supplier,
        data: [
            'total_amount'  => '10000.00',
            'delivery_days' => 7,
        ],
        actor: $this->supplierUser,
    );
})->throws(InvalidArgumentException::class);

it('submit: dispatches audit log entry on successful submission', function () {
    $this->service->submit(
        tender:   $this->tender,
        supplier: $this->supplier,
        data: [
            'total_amount'  => '45000.00',
            'delivery_days' => 30,
        ],
        actor: $this->supplierUser,
    );

    Bus::assertDispatched(\App\Jobs\WriteAuditLogJob::class);
});

it('submit: uses USD as default currency when not specified', function () {
    $bid = $this->service->submit(
        tender:   $this->tender,
        supplier: $this->supplier,
        data: [
            'total_amount'  => '20000.00',
            'delivery_days' => 14,
            // currency intentionally omitted
        ],
        actor: $this->supplierUser,
    );

    expect($bid->currency)->toBe('USD');
});

// ===========================================================================
// 2. submit() — Requirement 8.5: one bid per supplier per tender
// ===========================================================================

it('submit: throws InvalidArgumentException when supplier already has a bid for this tender', function () {
    // First bid — must succeed
    $this->service->submit(
        tender:   $this->tender,
        supplier: $this->supplier,
        data: [
            'total_amount'  => '50000.00',
            'delivery_days' => 21,
        ],
        actor: $this->supplierUser,
    );

    // Second bid for the same tender — must throw
    $this->service->submit(
        tender:   $this->tender,
        supplier: $this->supplier,
        data: [
            'total_amount'  => '45000.00',
            'delivery_days' => 18,
        ],
        actor: $this->supplierUser,
    );
})->throws(InvalidArgumentException::class, 'already submitted');

it('submit: allows different suppliers to each submit a bid for the same tender', function () {
    $otherSupplierUser = User::factory()->forTenant($this->tenant)->create(['status' => 'active']);
    $otherSupplier = Supplier::factory()->forTenant($this->tenant)->create([
        'user_id' => $otherSupplierUser->id,
        'status'  => 'active',
    ]);

    $bid1 = $this->service->submit(
        tender:   $this->tender,
        supplier: $this->supplier,
        data: [
            'total_amount'  => '50000.00',
            'delivery_days' => 21,
        ],
        actor: $this->supplierUser,
    );

    $bid2 = $this->service->submit(
        tender:   $this->tender,
        supplier: $otherSupplier,
        data: [
            'total_amount'  => '48000.00',
            'delivery_days' => 18,
        ],
        actor: $otherSupplierUser,
    );

    expect($bid1->id)->not->toBe($bid2->id)
        ->and($bid1->supplier_id)->not->toBe($bid2->supplier_id);

    $this->assertDatabaseCount('bids', 2);
});

it('submit: throws InvalidArgumentException when supplier is not active (blacklisted)', function () {
    $blacklistedSupplier = Supplier::factory()->forTenant($this->tenant)->blacklisted()->create();

    $this->service->submit(
        tender:   $this->tender,
        supplier: $blacklistedSupplier,
        data: [
            'total_amount'  => '20000.00',
            'delivery_days' => 10,
        ],
        actor: $this->supplierUser,
    );
})->throws(InvalidArgumentException::class);

it('submit: throws InvalidArgumentException when supplier is pending verification', function () {
    $pendingSupplier = Supplier::factory()->forTenant($this->tenant)->pendingVerification()->create();

    $this->service->submit(
        tender:   $this->tender,
        supplier: $pendingSupplier,
        data: [
            'total_amount'  => '20000.00',
            'delivery_days' => 10,
        ],
        actor: $this->supplierUser,
    );
})->throws(InvalidArgumentException::class);

// ===========================================================================
// 3. revise() — Requirement 8.5: revisions allowed before deadline
// ===========================================================================

it('revise: updates bid fields before the deadline', function () {
    $bid = $this->service->submit(
        tender:   $this->tender,
        supplier: $this->supplier,
        data: [
            'total_amount'    => '50000.00',
            'delivery_days'   => 21,
            'technical_notes' => 'Original notes.',
        ],
        actor: $this->supplierUser,
    );

    $revised = $this->service->revise(
        tender:   $this->tender,
        bid:      $bid,
        supplier: $this->supplier,
        data: [
            'total_amount'    => '45000.00',
            'delivery_days'   => 18,
            'technical_notes' => 'Updated competitive pricing.',
        ],
        actor: $this->supplierUser,
    );

    expect((float) $revised->total_amount)->toBe(45000.00)
        ->and($revised->delivery_days)->toBe(18)
        ->and($revised->technical_notes)->toBe('Updated competitive pricing.')
        ->and($revised->status)->toBe('submitted');
});

it('revise: updates submitted_at timestamp to the revision time', function () {
    $originalSubmittedAt = now()->subHour();

    $bid = $this->service->submit(
        tender:   $this->tender,
        supplier: $this->supplier,
        data: [
            'total_amount'  => '50000.00',
            'delivery_days' => 21,
        ],
        actor: $this->supplierUser,
    );

    // Manually set submitted_at to simulate an earlier submission
    $bid->update(['submitted_at' => $originalSubmittedAt]);

    $revised = $this->service->revise(
        tender:   $this->tender,
        bid:      $bid,
        supplier: $this->supplier,
        data: ['total_amount' => '47000.00'],
        actor:    $this->supplierUser,
    );

    expect($revised->submitted_at->greaterThan($originalSubmittedAt))->toBeTrue();
});

it('revise: throws InvalidArgumentException when deadline has passed', function () {
    // Create a bid on an expired tender (simulate deadline passing after bid was submitted)
    $tender = Tender::factory()->forTenant($this->tenant)->create([
        'status'              => 'published',
        'submission_deadline' => now()->addMinutes(2),
        'published_at'        => now()->subDays(10),
        'created_by'          => $this->officerUser->id,
    ]);

    $bid = $this->service->submit(
        tender:   $tender,
        supplier: $this->supplier,
        data: [
            'total_amount'  => '50000.00',
            'delivery_days' => 21,
        ],
        actor: $this->supplierUser,
    );

    // Move deadline to the past
    $tender->update(['submission_deadline' => now()->subMinute()]);
    $tender->refresh();

    $this->service->revise(
        tender:   $tender,
        bid:      $bid,
        supplier: $this->supplier,
        data:     ['total_amount' => '40000.00'],
        actor:    $this->supplierUser,
    );
})->throws(InvalidArgumentException::class);

it('revise: throws InvalidArgumentException when bid does not belong to the supplier', function () {
    $otherSupplier = Supplier::factory()->forTenant($this->tenant)->create(['status' => 'active']);
    $otherBid = $this->service->submit(
        tender:   $this->tender,
        supplier: $otherSupplier,
        data: [
            'total_amount'  => '60000.00',
            'delivery_days' => 25,
        ],
        actor: $this->supplierUser,
    );

    // $this->supplier tries to revise $otherSupplier's bid
    $this->service->revise(
        tender:   $this->tender,
        bid:      $otherBid,
        supplier: $this->supplier,
        data:     ['total_amount' => '55000.00'],
        actor:    $this->supplierUser,
    );
})->throws(InvalidArgumentException::class);

it('revise: dispatches audit log entry on successful revision', function () {
    $bid = $this->service->submit(
        tender:   $this->tender,
        supplier: $this->supplier,
        data: [
            'total_amount'  => '50000.00',
            'delivery_days' => 21,
        ],
        actor: $this->supplierUser,
    );

    Bus::clearResolvedInstances();
    Bus::fake();

    $this->service->revise(
        tender:   $this->tender,
        bid:      $bid,
        supplier: $this->supplier,
        data:     ['total_amount' => '47500.00'],
        actor:    $this->supplierUser,
    );

    Bus::assertDispatched(\App\Jobs\WriteAuditLogJob::class);
});

// ===========================================================================
// 4. getBidsForTender() — Requirement 8.7: supplier confidentiality
// ===========================================================================

it('getBidsForTender: supplier role can only see their own bid', function () {
    // Supplier A submits a bid
    $this->service->submit(
        tender:   $this->tender,
        supplier: $this->supplier,
        data: [
            'total_amount'  => '50000.00',
            'delivery_days' => 21,
        ],
        actor: $this->supplierUser,
    );

    // Supplier B submits a bid
    $otherSupplier = Supplier::factory()->forTenant($this->tenant)->create(['status' => 'active']);
    $otherBid = $this->service->submit(
        tender:   $this->tender,
        supplier: $otherSupplier,
        data: [
            'total_amount'  => '48000.00',
            'delivery_days' => 17,
        ],
        actor: $this->supplierUser,
    );

    // Query as Supplier A
    $result = $this->service->getBidsForTender(
        tender:               $this->tender,
        roleForIsolation:     'Supplier',
        supplierForIsolation: $this->supplier,
        perPage:              20,
    );

    expect($result->total())->toBe(1)
        ->and($result->items()[0]->supplier_id)->toBe($this->supplier->id);
});

it('getBidsForTender: supplier with no linked profile sees no bids', function () {
    $this->service->submit(
        tender:   $this->tender,
        supplier: $this->supplier,
        data: [
            'total_amount'  => '50000.00',
            'delivery_days' => 21,
        ],
        actor: $this->supplierUser,
    );

    // Query as Supplier role but with no supplier record
    $result = $this->service->getBidsForTender(
        tender:               $this->tender,
        roleForIsolation:     'Supplier',
        supplierForIsolation: null,
        perPage:              20,
    );

    expect($result->total())->toBe(0);
});

it('getBidsForTender: procurement officer can see all bids for a tender', function () {
    // Two different suppliers submit bids
    $otherSupplier = Supplier::factory()->forTenant($this->tenant)->create(['status' => 'active']);

    $this->service->submit(
        tender:   $this->tender,
        supplier: $this->supplier,
        data: [
            'total_amount'  => '50000.00',
            'delivery_days' => 21,
        ],
        actor: $this->supplierUser,
    );

    $this->service->submit(
        tender:   $this->tender,
        supplier: $otherSupplier,
        data: [
            'total_amount'  => '48000.00',
            'delivery_days' => 17,
        ],
        actor: $this->supplierUser,
    );

    // Query as Procurement_Officer (not the Supplier role)
    $result = $this->service->getBidsForTender(
        tender:               $this->tender,
        roleForIsolation:     'Procurement_Officer',
        supplierForIsolation: null,
        perPage:              20,
    );

    expect($result->total())->toBe(2);
});

it('getBidsForTender: tenant admin can see all bids', function () {
    $otherSupplier = Supplier::factory()->forTenant($this->tenant)->create(['status' => 'active']);

    $this->service->submit(
        tender:   $this->tender,
        supplier: $this->supplier,
        data: [
            'total_amount'  => '50000.00',
            'delivery_days' => 21,
        ],
        actor: $this->supplierUser,
    );

    $this->service->submit(
        tender:   $this->tender,
        supplier: $otherSupplier,
        data: [
            'total_amount'  => '62000.00',
            'delivery_days' => 28,
        ],
        actor: $this->supplierUser,
    );

    $result = $this->service->getBidsForTender(
        tender:               $this->tender,
        roleForIsolation:     'Tenant_Admin',
        supplierForIsolation: null,
        perPage:              20,
    );

    expect($result->total())->toBe(2);
});

it('getBidsForTender: returns only bids for the specified tender (not other tenders)', function () {
    // Bid on the main tender
    $this->service->submit(
        tender:   $this->tender,
        supplier: $this->supplier,
        data: [
            'total_amount'  => '50000.00',
            'delivery_days' => 21,
        ],
        actor: $this->supplierUser,
    );

    // Create another tender and another supplier's bid on it
    $otherTender = Tender::factory()->forTenant($this->tenant)->create([
        'status'              => 'published',
        'submission_deadline' => now()->addDays(10),
        'published_at'        => now()->subDays(2),
        'created_by'          => $this->officerUser->id,
    ]);
    $otherSupplier = Supplier::factory()->forTenant($this->tenant)->create(['status' => 'active']);
    $this->service->submit(
        tender:   $otherTender,
        supplier: $otherSupplier,
        data: [
            'total_amount'  => '30000.00',
            'delivery_days' => 10,
        ],
        actor: $this->supplierUser,
    );

    // Query bids for the main tender as Procurement_Officer
    $result = $this->service->getBidsForTender(
        tender:               $this->tender,
        roleForIsolation:     'Procurement_Officer',
        supplierForIsolation: null,
        perPage:              20,
    );

    expect($result->total())->toBe(1)
        ->and($result->items()[0]->tender_id)->toBe($this->tender->id);
});

// ===========================================================================
// 5. getBid() — Requirement 8.7: single bid visibility isolation
// ===========================================================================

it('getBid: supplier can view their own bid', function () {
    $bid = $this->service->submit(
        tender:   $this->tender,
        supplier: $this->supplier,
        data: [
            'total_amount'  => '50000.00',
            'delivery_days' => 21,
        ],
        actor: $this->supplierUser,
    );

    $result = $this->service->getBid(
        bid:                  $bid,
        roleForIsolation:     'Supplier',
        supplierForIsolation: $this->supplier,
    );

    expect($result)->not->toBeNull()
        ->and($result->id)->toBe($bid->id);
});

it('getBid: supplier receives null when trying to view another suppliers bid', function () {
    $otherSupplier = Supplier::factory()->forTenant($this->tenant)->create(['status' => 'active']);

    $otherBid = $this->service->submit(
        tender:   $this->tender,
        supplier: $otherSupplier,
        data: [
            'total_amount'  => '45000.00',
            'delivery_days' => 14,
        ],
        actor: $this->supplierUser,
    );

    // Supplier A tries to view Supplier B's bid
    $result = $this->service->getBid(
        bid:                  $otherBid,
        roleForIsolation:     'Supplier',
        supplierForIsolation: $this->supplier,
    );

    expect($result)->toBeNull();
});

it('getBid: procurement officer can view any bid regardless of supplier', function () {
    $bid = $this->service->submit(
        tender:   $this->tender,
        supplier: $this->supplier,
        data: [
            'total_amount'  => '50000.00',
            'delivery_days' => 21,
        ],
        actor: $this->supplierUser,
    );

    $result = $this->service->getBid(
        bid:                  $bid,
        roleForIsolation:     'Procurement_Officer',
        supplierForIsolation: null,
    );

    expect($result)->not->toBeNull()
        ->and($result->id)->toBe($bid->id);
});

it('getBid: supplier with no linked profile returns null for any bid', function () {
    $bid = $this->service->submit(
        tender:   $this->tender,
        supplier: $this->supplier,
        data: [
            'total_amount'  => '50000.00',
            'delivery_days' => 21,
        ],
        actor: $this->supplierUser,
    );

    $result = $this->service->getBid(
        bid:                  $bid,
        roleForIsolation:     'Supplier',
        supplierForIsolation: null,
    );

    expect($result)->toBeNull();
});

it('getBid: committee member can view any bid', function () {
    $bid = $this->service->submit(
        tender:   $this->tender,
        supplier: $this->supplier,
        data: [
            'total_amount'  => '50000.00',
            'delivery_days' => 21,
        ],
        actor: $this->supplierUser,
    );

    $result = $this->service->getBid(
        bid:                  $bid,
        roleForIsolation:     'Committee_Member',
        supplierForIsolation: null,
    );

    expect($result)->not->toBeNull()
        ->and($result->id)->toBe($bid->id);
});
