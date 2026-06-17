<?php

/**
 * Unit / Feature tests for TenderService.
 *
 * Covers the requirements for task 9.1:
 *  1. create()          — creates a tender in `draft` status with validation
 *  2. publish()         — transitions draft → published; notifies active suppliers
 *  3. cancel()          — transitions to cancelled; notifies bidding suppliers
 *  4. extendDeadline()  — extends deadline only if it hasn't passed yet
 *  5. closeExpired()    — automatically closes tenders past their deadline (scheduler)
 *  6. tender type support: open, restricted, single_source
 *
 * **Validates: Requirements 8.1, 8.2, 8.3, 8.6, 8.8, 8.9, 8.10**
 */

use App\Models\Bid;
use App\Models\Notification;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\Tender;
use App\Models\User;
use App\Services\TenderService;
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

    $this->tenant  = Tenant::factory()->create(['status' => 'active', 'tenant_code' => 'ACME']);
    $this->officer = User::factory()->forTenant($this->tenant)->create();

    \Spatie\Permission\Models\Role::firstOrCreate(
        ['name' => 'Procurement_Officer', 'guard_name' => 'api']
    );
    $this->officer->assignRole('Procurement_Officer');

    $this->service = new TenderService();

    app()->instance('tenant', $this->tenant);
});

// ===========================================================================
// 1. create()
// Requirements: 8.1
// ===========================================================================

it('create: creates a tender in draft status', function () {
    $tender = $this->service->create(
        data: [
            'title'               => 'Supply of Office Furniture',
            'description'         => 'Tender for supply of ergonomic office furniture.',
            'category'            => 'Office Supplies',
            'tender_type'         => 'open',
            'estimated_value'     => '50000.00',
            'submission_deadline' => now()->addDays(30)->toIso8601String(),
        ],
        actor:    $this->officer,
        tenantId: $this->tenant->id,
    );

    expect($tender->status)->toBe('draft')
        ->and($tender->title)->toBe('Supply of Office Furniture')
        ->and($tender->tender_type)->toBe('open')
        ->and($tender->tenant_id)->toBe($this->tenant->id)
        ->and($tender->created_by)->toBe($this->officer->id);

    $this->assertDatabaseHas('tenders', [
        'id'     => $tender->id,
        'status' => 'draft',
    ]);
});

it('create: auto-generates a reference number when not provided', function () {
    $tender = $this->service->create(
        data: [
            'title'               => 'IT Equipment Procurement',
            'description'         => 'Supply of laptops and workstations.',
            'category'            => 'IT Equipment',
            'tender_type'         => 'open',
            'estimated_value'     => '120000.00',
            'submission_deadline' => now()->addDays(14)->toIso8601String(),
        ],
        actor:    $this->officer,
        tenantId: $this->tenant->id,
    );

    expect($tender->reference_number)->not->toBeEmpty();
    expect($tender->reference_number)->toStartWith('TDR-');
});

it('create: uses provided reference number when supplied', function () {
    $tender = $this->service->create(
        data: [
            'title'               => 'Security Services Tender',
            'description'         => 'Provision of 24h security services.',
            'category'            => 'Security',
            'tender_type'         => 'restricted',
            'estimated_value'     => '75000.00',
            'submission_deadline' => now()->addDays(20)->toIso8601String(),
            'reference_number'    => 'CUSTOM-REF-001',
        ],
        actor:    $this->officer,
        tenantId: $this->tenant->id,
    );

    expect($tender->reference_number)->toBe('CUSTOM-REF-001');
});

it('create: dispatches audit log on creation', function () {
    $this->service->create(
        data: [
            'title'               => 'Catering Services',
            'description'         => 'Annual catering contract.',
            'category'            => 'Catering',
            'tender_type'         => 'open',
            'estimated_value'     => '30000.00',
            'submission_deadline' => now()->addDays(21)->toIso8601String(),
        ],
        actor:    $this->officer,
        tenantId: $this->tenant->id,
    );

    Bus::assertDispatched(\App\Jobs\WriteAuditLogJob::class);
});

it('create: throws InvalidArgumentException when required fields are missing', function () {
    $this->service->create(
        data:     ['title' => 'Incomplete Tender'],
        actor:    $this->officer,
        tenantId: $this->tenant->id,
    );
})->throws(InvalidArgumentException::class);

it('create: throws InvalidArgumentException for invalid tender_type', function () {
    $this->service->create(
        data: [
            'title'               => 'Test Tender',
            'description'         => 'Some description.',
            'category'            => 'IT Equipment',
            'tender_type'         => 'invalid_type',
            'estimated_value'     => '10000.00',
            'submission_deadline' => now()->addDays(10)->toIso8601String(),
        ],
        actor:    $this->officer,
        tenantId: $this->tenant->id,
    );
})->throws(InvalidArgumentException::class);

it('create: throws InvalidArgumentException when estimated_value is zero or negative', function () {
    $this->service->create(
        data: [
            'title'               => 'Zero Value Tender',
            'description'         => 'Some description.',
            'category'            => 'IT Equipment',
            'tender_type'         => 'open',
            'estimated_value'     => '0',
            'submission_deadline' => now()->addDays(10)->toIso8601String(),
        ],
        actor:    $this->officer,
        tenantId: $this->tenant->id,
    );
})->throws(InvalidArgumentException::class);

it('create: throws InvalidArgumentException when submission_deadline is in the past', function () {
    $this->service->create(
        data: [
            'title'               => 'Past Deadline Tender',
            'description'         => 'Some description.',
            'category'            => 'IT Equipment',
            'tender_type'         => 'open',
            'estimated_value'     => '10000.00',
            'submission_deadline' => now()->subDay()->toIso8601String(),
        ],
        actor:    $this->officer,
        tenantId: $this->tenant->id,
    );
})->throws(InvalidArgumentException::class);

it('create: supports all valid tender types', function () {
    foreach (['open', 'restricted', 'single_source'] as $type) {
        $tender = $this->service->create(
            data: [
                'title'               => "Tender type: {$type}",
                'description'         => 'Description.',
                'category'            => 'IT Equipment',
                'tender_type'         => $type,
                'estimated_value'     => '10000.00',
                'submission_deadline' => now()->addDays(30)->toIso8601String(),
            ],
            actor:    $this->officer,
            tenantId: $this->tenant->id,
        );

        expect($tender->tender_type)->toBe($type);
    }
});

// ===========================================================================
// 2. publish()
// Requirements: 8.2, 8.10
// ===========================================================================

it('publish: transitions a draft tender to published status', function () {
    $tender = Tender::factory()->forTenant($this->tenant)->draft()->create(['created_by' => $this->officer->id]);

    $published = $this->service->publish(
        tender: $tender,
        actor:  $this->officer,
    );

    expect($published->status)->toBe('published')
        ->and($published->published_at)->not->toBeNull();

    $this->assertDatabaseHas('tenders', ['id' => $tender->id, 'status' => 'published']);
});

it('publish: dispatches audit log on publication', function () {
    $tender = Tender::factory()->forTenant($this->tenant)->draft()->create(['created_by' => $this->officer->id]);

    $this->service->publish(tender: $tender, actor: $this->officer);

    Bus::assertDispatched(\App\Jobs\WriteAuditLogJob::class);
});

it('publish: throws InvalidArgumentException when tender is not in draft status', function () {
    $tender = Tender::factory()->forTenant($this->tenant)->published()->create(['created_by' => $this->officer->id]);

    $this->service->publish(tender: $tender, actor: $this->officer);
})->throws(InvalidArgumentException::class);

it('publish: throws when single_source tender is published without supplier_id', function () {
    $tender = Tender::factory()
        ->forTenant($this->tenant)
        ->draft()
        ->create([
            'tender_type' => 'single_source',
            'created_by'  => $this->officer->id,
        ]);

    $this->service->publish(tender: $tender, actor: $this->officer, data: []);
})->throws(InvalidArgumentException::class);

it('publish: notifies active suppliers matching the category for open tenders', function () {
    // Create a supplier user
    $supplierUser = User::factory()->forTenant($this->tenant)->create(['status' => 'active']);
    Supplier::factory()->forTenant($this->tenant)->create([
        'user_id'           => $supplierUser->id,
        'status'            => 'active',
        'business_category' => 'IT Equipment',
    ]);

    $tender = Tender::factory()->forTenant($this->tenant)->draft()->create([
        'category'    => 'IT Equipment',
        'tender_type' => 'open',
        'created_by'  => $this->officer->id,
    ]);

    $this->service->publish(tender: $tender, actor: $this->officer);

    $this->assertDatabaseHas('notifications', [
        'tenant_id'  => $this->tenant->id,
        'user_id'    => $supplierUser->id,
        'event_type' => 'tender_published',
    ]);
});

it('publish: does not notify suppliers with mismatched category', function () {
    $supplierUser = User::factory()->forTenant($this->tenant)->create(['status' => 'active']);
    Supplier::factory()->forTenant($this->tenant)->create([
        'user_id'           => $supplierUser->id,
        'status'            => 'active',
        'business_category' => 'Construction',   // different category
    ]);

    $tender = Tender::factory()->forTenant($this->tenant)->draft()->create([
        'category'    => 'IT Equipment',
        'tender_type' => 'open',
        'created_by'  => $this->officer->id,
    ]);

    $this->service->publish(tender: $tender, actor: $this->officer);

    $this->assertDatabaseMissing('notifications', [
        'tenant_id' => $this->tenant->id,
        'user_id'   => $supplierUser->id,
    ]);
});

it('publish: does not notify blacklisted suppliers', function () {
    $supplierUser = User::factory()->forTenant($this->tenant)->create(['status' => 'active']);
    Supplier::factory()->forTenant($this->tenant)->create([
        'user_id'           => $supplierUser->id,
        'status'            => 'blacklisted',    // not active
        'business_category' => 'IT Equipment',
    ]);

    $tender = Tender::factory()->forTenant($this->tenant)->draft()->create([
        'category'    => 'IT Equipment',
        'tender_type' => 'open',
        'created_by'  => $this->officer->id,
    ]);

    $this->service->publish(tender: $tender, actor: $this->officer);

    $this->assertDatabaseMissing('notifications', [
        'tenant_id' => $this->tenant->id,
        'user_id'   => $supplierUser->id,
    ]);
});

it('publish: notifies only the specified supplier for single_source tenders', function () {
    // Create two supplier users
    $targetUser = User::factory()->forTenant($this->tenant)->create(['status' => 'active']);
    $targetSupplier = Supplier::factory()->forTenant($this->tenant)->create([
        'user_id'           => $targetUser->id,
        'status'            => 'active',
        'business_category' => 'IT Equipment',
    ]);

    $otherUser = User::factory()->forTenant($this->tenant)->create(['status' => 'active']);
    Supplier::factory()->forTenant($this->tenant)->create([
        'user_id'           => $otherUser->id,
        'status'            => 'active',
        'business_category' => 'IT Equipment',
    ]);

    $tender = Tender::factory()->forTenant($this->tenant)->draft()->create([
        'category'    => 'IT Equipment',
        'tender_type' => 'single_source',
        'created_by'  => $this->officer->id,
    ]);

    $this->service->publish(
        tender: $tender,
        actor:  $this->officer,
        data:   ['supplier_id' => $targetSupplier->id],
    );

    // Target supplier receives a notification
    $this->assertDatabaseHas('notifications', [
        'tenant_id' => $this->tenant->id,
        'user_id'   => $targetUser->id,
        'event_type' => 'tender_published',
    ]);

    // Other supplier does NOT receive a notification
    $this->assertDatabaseMissing('notifications', [
        'tenant_id' => $this->tenant->id,
        'user_id'   => $otherUser->id,
    ]);
});

// ===========================================================================
// 3. cancel()
// Requirements: 8.9
// ===========================================================================

it('cancel: transitions draft tender to cancelled with reason', function () {
    $tender = Tender::factory()->forTenant($this->tenant)->draft()->create(['created_by' => $this->officer->id]);

    $cancelled = $this->service->cancel(
        tender:             $tender,
        actor:              $this->officer,
        cancellationReason: 'Budget constraints.',
    );

    expect($cancelled->status)->toBe('cancelled')
        ->and($cancelled->cancellation_reason)->toBe('Budget constraints.');

    $this->assertDatabaseHas('tenders', ['id' => $tender->id, 'status' => 'cancelled']);
});

it('cancel: transitions published tender to cancelled', function () {
    $tender = Tender::factory()->forTenant($this->tenant)->published()->create(['created_by' => $this->officer->id]);

    $cancelled = $this->service->cancel(
        tender:             $tender,
        actor:              $this->officer,
        cancellationReason: 'Policy change.',
    );

    expect($cancelled->status)->toBe('cancelled');
});

it('cancel: dispatches audit log on cancellation', function () {
    $tender = Tender::factory()->forTenant($this->tenant)->draft()->create(['created_by' => $this->officer->id]);

    $this->service->cancel($tender, $this->officer, 'Policy changed.');

    Bus::assertDispatched(\App\Jobs\WriteAuditLogJob::class);
});

it('cancel: throws InvalidArgumentException when tender is already closed', function () {
    $tender = Tender::factory()->forTenant($this->tenant)->closed()->create(['created_by' => $this->officer->id]);

    $this->service->cancel($tender, $this->officer, 'Some reason.');
})->throws(InvalidArgumentException::class);

it('cancel: throws InvalidArgumentException when cancellation reason is empty', function () {
    $tender = Tender::factory()->forTenant($this->tenant)->draft()->create(['created_by' => $this->officer->id]);

    $this->service->cancel($tender, $this->officer, '   ');
})->throws(InvalidArgumentException::class);

it('cancel: notifies suppliers who submitted bids', function () {
    $tender = Tender::factory()->forTenant($this->tenant)->published()->create(['created_by' => $this->officer->id]);

    // Create a bidding supplier
    $supplierUser = User::factory()->forTenant($this->tenant)->create(['status' => 'active']);
    $supplier = Supplier::factory()->forTenant($this->tenant)->create([
        'user_id' => $supplierUser->id,
        'status'  => 'active',
    ]);

    // Create a submitted bid
    Bid::create([
        'tenant_id'   => $this->tenant->id,
        'tender_id'   => $tender->id,
        'supplier_id' => $supplier->id,
        'total_amount' => '50000.00',
        'currency'    => 'USD',
        'delivery_days' => 14,
        'status'      => 'submitted',
        'submitted_at' => now()->subHours(2),
    ]);

    $this->service->cancel($tender, $this->officer, 'Project cancelled by management.');

    $this->assertDatabaseHas('notifications', [
        'tenant_id'  => $this->tenant->id,
        'user_id'    => $supplierUser->id,
        'event_type' => 'tender_cancelled',
    ]);
});

it('cancel: does not notify suppliers with draft bids (not submitted)', function () {
    $tender = Tender::factory()->forTenant($this->tenant)->published()->create(['created_by' => $this->officer->id]);

    $supplierUser = User::factory()->forTenant($this->tenant)->create(['status' => 'active']);
    $supplier = Supplier::factory()->forTenant($this->tenant)->create([
        'user_id' => $supplierUser->id,
        'status'  => 'active',
    ]);

    // Create a draft bid (not submitted)
    Bid::create([
        'tenant_id'    => $this->tenant->id,
        'tender_id'    => $tender->id,
        'supplier_id'  => $supplier->id,
        'total_amount' => '50000.00',
        'currency'     => 'USD',
        'delivery_days' => 14,
        'status'       => 'draft',
    ]);

    $this->service->cancel($tender, $this->officer, 'Project cancelled.');

    $this->assertDatabaseMissing('notifications', [
        'tenant_id' => $this->tenant->id,
        'user_id'   => $supplierUser->id,
    ]);
});

// ===========================================================================
// 4. extendDeadline()
// Requirements: 8.8
// ===========================================================================

it('extendDeadline: extends the deadline of a published tender', function () {
    $originalDeadline = now()->addDays(10);

    $tender = Tender::factory()->forTenant($this->tenant)->create([
        'status'              => 'published',
        'submission_deadline' => $originalDeadline,
        'published_at'        => now()->subDays(5),
        'created_by'          => $this->officer->id,
    ]);

    $newDeadline = now()->addDays(20);

    $extended = $this->service->extendDeadline(
        tender:      $tender,
        actor:       $this->officer,
        newDeadline: $newDeadline->toIso8601String(),
    );

    expect($extended->submission_deadline->greaterThan($originalDeadline))->toBeTrue();
});

it('extendDeadline: dispatches audit log on deadline extension', function () {
    $tender = Tender::factory()->forTenant($this->tenant)->create([
        'status'              => 'published',
        'submission_deadline' => now()->addDays(10),
        'published_at'        => now()->subDays(3),
        'created_by'          => $this->officer->id,
    ]);

    $this->service->extendDeadline($tender, $this->officer, now()->addDays(20)->toIso8601String());

    Bus::assertDispatched(\App\Jobs\WriteAuditLogJob::class);
});

it('extendDeadline: throws InvalidArgumentException when tender is not published', function () {
    $tender = Tender::factory()->forTenant($this->tenant)->draft()->create(['created_by' => $this->officer->id]);

    $this->service->extendDeadline($tender, $this->officer, now()->addDays(20)->toIso8601String());
})->throws(InvalidArgumentException::class);

it('extendDeadline: throws InvalidArgumentException when the original deadline has already passed', function () {
    // Create a tender with a past deadline but still "published" status (simulate lag before scheduler)
    $tender = Tender::factory()->forTenant($this->tenant)->create([
        'status'              => 'published',
        'submission_deadline' => now()->subHours(1),   // deadline has passed
        'published_at'        => now()->subDays(14),
        'created_by'          => $this->officer->id,
    ]);

    $this->service->extendDeadline($tender, $this->officer, now()->addDays(10)->toIso8601String());
})->throws(InvalidArgumentException::class);

it('extendDeadline: throws InvalidArgumentException when new deadline is not after current deadline', function () {
    $currentDeadline = now()->addDays(10);

    $tender = Tender::factory()->forTenant($this->tenant)->create([
        'status'              => 'published',
        'submission_deadline' => $currentDeadline,
        'published_at'        => now()->subDays(3),
        'created_by'          => $this->officer->id,
    ]);

    // Try to set deadline to same day or earlier
    $this->service->extendDeadline($tender, $this->officer, $currentDeadline->subDay()->toIso8601String());
})->throws(InvalidArgumentException::class);

// ===========================================================================
// 5. closeExpired() — automatic closure via scheduler
// Requirements: 8.6
// ===========================================================================

it('closeExpired: closes published tenders whose deadline has passed', function () {
    // Published tender with expired deadline
    $expiredTender = Tender::factory()->forTenant($this->tenant)->create([
        'status'              => 'published',
        'submission_deadline' => now()->subHours(2),
        'published_at'        => now()->subDays(14),
        'created_by'          => $this->officer->id,
    ]);

    $closed = $this->service->closeExpired();

    expect($closed)->toBe(1);
    $this->assertDatabaseHas('tenders', ['id' => $expiredTender->id, 'status' => 'closed']);
});

it('closeExpired: leaves tenders with future deadlines untouched', function () {
    $futureTender = Tender::factory()->forTenant($this->tenant)->published()->create([
        'created_by' => $this->officer->id,
    ]);

    $closed = $this->service->closeExpired();

    expect($closed)->toBe(0);
    $this->assertDatabaseHas('tenders', ['id' => $futureTender->id, 'status' => 'published']);
});

it('closeExpired: does not close already-closed or cancelled tenders', function () {
    Tender::factory()->forTenant($this->tenant)->closed()->create([
        'created_by' => $this->officer->id,
    ]);
    Tender::factory()->forTenant($this->tenant)->create([
        'status'              => 'cancelled',
        'submission_deadline' => now()->subDays(1),
        'created_by'          => $this->officer->id,
    ]);

    $closed = $this->service->closeExpired();

    expect($closed)->toBe(0);
});

it('closeExpired: disqualifies draft bids when tender is closed', function () {
    $expiredTender = Tender::factory()->forTenant($this->tenant)->create([
        'status'              => 'published',
        'submission_deadline' => now()->subHours(1),
        'published_at'        => now()->subDays(10),
        'created_by'          => $this->officer->id,
    ]);

    $supplier = Supplier::factory()->forTenant($this->tenant)->create(['status' => 'active']);
    $bid = Bid::create([
        'tenant_id'    => $this->tenant->id,
        'tender_id'    => $expiredTender->id,
        'supplier_id'  => $supplier->id,
        'total_amount' => '25000.00',
        'currency'     => 'USD',
        'delivery_days' => 14,
        'status'       => 'draft',   // was never formally submitted
    ]);

    $this->service->closeExpired();

    $this->assertDatabaseHas('bids', ['id' => $bid->id, 'status' => 'disqualified']);
});

it('closeExpired: does not disqualify submitted bids when tender is closed', function () {
    $expiredTender = Tender::factory()->forTenant($this->tenant)->create([
        'status'              => 'published',
        'submission_deadline' => now()->subHours(1),
        'published_at'        => now()->subDays(10),
        'created_by'          => $this->officer->id,
    ]);

    $supplier = Supplier::factory()->forTenant($this->tenant)->create(['status' => 'active']);
    $bid = Bid::create([
        'tenant_id'    => $this->tenant->id,
        'tender_id'    => $expiredTender->id,
        'supplier_id'  => $supplier->id,
        'total_amount' => '25000.00',
        'currency'     => 'USD',
        'delivery_days' => 14,
        'status'       => 'submitted',  // properly submitted before deadline
        'submitted_at' => now()->subHours(3),
    ]);

    $this->service->closeExpired();

    $this->assertDatabaseHas('bids', ['id' => $bid->id, 'status' => 'submitted']);
});

it('closeExpired: closes multiple expired tenders at once', function () {
    // Create 3 expired tenders
    Tender::factory()->forTenant($this->tenant)->count(3)->create([
        'status'              => 'published',
        'submission_deadline' => now()->subHours(1),
        'published_at'        => now()->subDays(7),
        'created_by'          => $this->officer->id,
    ]);

    $closed = $this->service->closeExpired();

    expect($closed)->toBe(3);
});

it('closeExpired: dispatches audit log for each closed tender', function () {
    Tender::factory()->forTenant($this->tenant)->create([
        'status'              => 'published',
        'submission_deadline' => now()->subHours(1),
        'published_at'        => now()->subDays(7),
        'created_by'          => $this->officer->id,
    ]);

    $this->service->closeExpired();

    Bus::assertDispatched(\App\Jobs\WriteAuditLogJob::class);
});

// ===========================================================================
// 6. search()
// Requirements: 8.1
// ===========================================================================

it('search: returns paginated results scoped to the active tenant', function () {
    Tender::factory()->forTenant($this->tenant)->count(5)->create(['created_by' => $this->officer->id]);

    $result = $this->service->search([], 20);

    expect($result->total())->toBe(5);
});

it('search: filters by status', function () {
    Tender::factory()->forTenant($this->tenant)->draft()->count(3)->create(['created_by' => $this->officer->id]);
    Tender::factory()->forTenant($this->tenant)->published()->count(2)->create(['created_by' => $this->officer->id]);

    $result = $this->service->search(['status' => 'draft'], 20);

    expect($result->total())->toBe(3);
});

it('search: filters by tender_type', function () {
    Tender::factory()->forTenant($this->tenant)->draft()->count(2)->create([
        'tender_type' => 'open',
        'created_by'  => $this->officer->id,
    ]);
    Tender::factory()->forTenant($this->tenant)->draft()->create([
        'tender_type' => 'restricted',
        'created_by'  => $this->officer->id,
    ]);

    $result = $this->service->search(['tender_type' => 'open'], 20);

    expect($result->total())->toBe(2);
});

it('search: filters by search term on title', function () {
    Tender::factory()->forTenant($this->tenant)->draft()->create([
        'title'      => 'Supply of Medical Gloves',
        'created_by' => $this->officer->id,
    ]);
    Tender::factory()->forTenant($this->tenant)->draft()->create([
        'title'      => 'IT Infrastructure Upgrade',
        'created_by' => $this->officer->id,
    ]);

    $result = $this->service->search(['search' => 'Medical'], 20);

    expect($result->total())->toBe(1)
        ->and($result->items()[0]->title)->toBe('Supply of Medical Gloves');
});
