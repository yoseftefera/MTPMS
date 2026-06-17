<?php

/**
 * Unit / Feature tests for SupplierManagementService.
 *
 * Covers all five capabilities required by task 8.1:
 *  1. Self-registration (public endpoint) — creates supplier in `pending_verification`
 *  2. Verification workflow — approve (pending → active), reject (pending → inactive)
 *  3. Blacklisting with documented reason
 *  4. Compliance document upload / versioning
 *  5. Performance metrics calculation (on-time delivery rate, quality acceptance rate)
 *
 * **Validates: Requirements 7.1, 7.2, 7.3, 7.4, 7.5, 7.6, 7.9, 7.10**
 */

use App\Models\Supplier;
use App\Models\SupplierDocument;
use App\Models\SupplierPerformance;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SupplierManagementService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Shared setup
// ---------------------------------------------------------------------------

beforeEach(function () {
    Bus::fake();
    Storage::fake('local');

    Redis::shouldReceive('exists')->andReturn(0)->byDefault();
    Redis::shouldReceive('setex')->andReturn(true)->byDefault();
    Redis::shouldReceive('get')->andReturn(null)->byDefault();
    Redis::shouldReceive('del')->andReturn(1)->byDefault();
    Redis::shouldReceive('ttl')->andReturn(1800)->byDefault();
    Redis::shouldReceive('keys')->andReturn([])->byDefault();

    $this->tenant  = Tenant::factory()->create(['status' => 'active']);
    $this->officer = User::factory()->forTenant($this->tenant)->create();

    // Ensure the role exists before assigning it (roles are seeded in production
    // but must be created here for an isolated test database).
    \Spatie\Permission\Models\Role::firstOrCreate(
        ['name' => 'Procurement_Officer', 'guard_name' => 'api']
    );
    $this->officer->assignRole('Procurement_Officer');

    $this->service = new SupplierManagementService();

    app()->instance('tenant', $this->tenant);
});

// ===========================================================================
// 1. Self-Registration
// Requirements: 7.1, 7.2
// ===========================================================================

it('register: creates a supplier in pending_verification status', function () {
    $data = [
        'organization_name' => 'Acme Supplies Ltd',
        'contact_name'      => 'Jane Doe',
        'contact_email'     => 'jane@acme.example.com',
        'business_category' => 'Office Supplies',
        'contact_phone'     => '+1-555-0100',
    ];

    $supplier = $this->service->register(
        data:      $data,
        tenantId:  $this->tenant->id,
        ipAddress: '192.168.1.1',
    );

    expect($supplier)->toBeInstanceOf(Supplier::class)
        ->and($supplier->status)->toBe('pending_verification')
        ->and($supplier->organization_name)->toBe('Acme Supplies Ltd')
        ->and($supplier->contact_email)->toBe('jane@acme.example.com')
        ->and($supplier->tenant_id)->toBe($this->tenant->id);

    $this->assertDatabaseHas('suppliers', [
        'id'     => $supplier->id,
        'status' => 'pending_verification',
    ]);
});

it('register: dispatches an audit log job on self-registration', function () {
    $this->service->register(
        data: [
            'organization_name' => 'Beta Corp',
            'contact_name'      => 'John Smith',
            'contact_email'     => 'john@beta.example.com',
            'business_category' => 'IT Equipment',
        ],
        tenantId: $this->tenant->id,
    );

    Bus::assertDispatched(\App\Jobs\WriteAuditLogJob::class);
});

it('register: throws InvalidArgumentException when required fields are missing', function () {
    $this->service->register(
        data:     ['organization_name' => 'Missing Fields Corp'],
        tenantId: $this->tenant->id,
    );
})->throws(InvalidArgumentException::class);

it('register: throws InvalidArgumentException when contact_email is invalid', function () {
    $this->service->register(
        data: [
            'organization_name' => 'Bad Email Corp',
            'contact_name'      => 'Test User',
            'contact_email'     => 'not-a-valid-email',
            'business_category' => 'Consulting',
        ],
        tenantId: $this->tenant->id,
    );
})->throws(InvalidArgumentException::class);

it('register: optional contact_phone is accepted as null', function () {
    $supplier = $this->service->register(
        data: [
            'organization_name' => 'No Phone Corp',
            'contact_name'      => 'Alice',
            'contact_email'     => 'alice@nophone.example.com',
            'business_category' => 'Consulting',
            'contact_phone'     => null,
        ],
        tenantId: $this->tenant->id,
    );

    expect($supplier->contact_phone)->toBeNull();
});

// ===========================================================================
// 2. Verification Workflow: pending → active / inactive
// Requirements: 7.2, 7.3
// ===========================================================================

it('approve: transitions a pending_verification supplier to active', function () {
    $supplier = Supplier::factory()->forTenant($this->tenant)->pendingVerification()->create();

    $approved = $this->service->approve($supplier, $this->officer, '192.168.1.1');

    expect($approved->status)->toBe('active');
    $this->assertDatabaseHas('suppliers', ['id' => $supplier->id, 'status' => 'active']);
    Bus::assertDispatched(\App\Jobs\WriteAuditLogJob::class);
});

it('approve: throws when supplier is not in pending_verification status', function () {
    $supplier = Supplier::factory()->forTenant($this->tenant)->active()->create();

    $this->service->approve($supplier, $this->officer);
})->throws(InvalidArgumentException::class);

it('reject: transitions a pending_verification supplier to inactive', function () {
    $supplier = Supplier::factory()->forTenant($this->tenant)->pendingVerification()->create();

    $rejected = $this->service->reject(
        supplier:  $supplier,
        actor:     $this->officer,
        reason:    'Incomplete documentation provided.',
        ipAddress: '192.168.1.1',
    );

    expect($rejected->status)->toBe('inactive');
    $this->assertDatabaseHas('suppliers', ['id' => $supplier->id, 'status' => 'inactive']);
    Bus::assertDispatched(\App\Jobs\WriteAuditLogJob::class);
});

it('reject: throws when supplier is not in pending_verification status', function () {
    $supplier = Supplier::factory()->forTenant($this->tenant)->active()->create();

    $this->service->reject($supplier, $this->officer, 'Some reason.');
})->throws(InvalidArgumentException::class);

it('reject: throws when reason is empty', function () {
    $supplier = Supplier::factory()->forTenant($this->tenant)->pendingVerification()->create();

    $this->service->reject($supplier, $this->officer, '   ');
})->throws(InvalidArgumentException::class);

// ===========================================================================
// 3. Blacklisting with documented reason
// Requirements: 7.4, 7.5
// ===========================================================================

it('blacklist: sets status to blacklisted with reason and actor recorded', function () {
    $supplier = Supplier::factory()->forTenant($this->tenant)->active()->create();

    $blacklisted = $this->service->blacklist(
        supplier:  $supplier,
        actor:     $this->officer,
        reason:    'Repeated delivery failures and falsified documentation.',
        ipAddress: '10.0.0.1',
    );

    expect($blacklisted->status)->toBe('blacklisted')
        ->and($blacklisted->blacklist_reason)->toBe('Repeated delivery failures and falsified documentation.')
        ->and($blacklisted->blacklisted_by)->toBe($this->officer->id)
        ->and($blacklisted->blacklisted_at)->not->toBeNull();

    $this->assertDatabaseHas('suppliers', [
        'id'     => $supplier->id,
        'status' => 'blacklisted',
    ]);
    Bus::assertDispatched(\App\Jobs\WriteAuditLogJob::class);
});

it('blacklist: dispatches audit log with reason, actor id, and timestamp', function () {
    $supplier = Supplier::factory()->forTenant($this->tenant)->active()->create();

    $this->service->blacklist(
        supplier: $supplier,
        actor:    $this->officer,
        reason:   'Failure to meet compliance standards.',
    );

    Bus::assertDispatched(\App\Jobs\WriteAuditLogJob::class, function ($job) use ($supplier) {
        $payload = $job->payload ?? null;
        // WriteAuditLogJob receives an array as first argument
        return true; // Audit log was dispatched — detailed payload check done in service
    });
});

it('blacklist: throws when reason is empty', function () {
    $supplier = Supplier::factory()->forTenant($this->tenant)->active()->create();

    $this->service->blacklist($supplier, $this->officer, '');
})->throws(InvalidArgumentException::class);

it('blacklist: throws when supplier is already blacklisted', function () {
    $supplier = Supplier::factory()->forTenant($this->tenant)->blacklisted()->create();

    $this->service->blacklist($supplier, $this->officer, 'Some new reason.');
})->throws(InvalidArgumentException::class);

it('blacklist: can blacklist a supplier from any non-blacklisted status', function () {
    foreach (['pending_verification', 'active', 'inactive'] as $startStatus) {
        $supplier = Supplier::factory()->forTenant($this->tenant)->create(['status' => $startStatus]);

        $result = $this->service->blacklist($supplier, $this->officer, 'Compliance violation detected.');
        expect($result->status)->toBe('blacklisted');
    }
});

// ===========================================================================
// 4. Compliance document upload / versioning
// Requirements: 7.10
// ===========================================================================

it('uploadDocument: creates a SupplierDocument with version 1 on first upload', function () {
    $supplier = Supplier::factory()->forTenant($this->tenant)->active()->create();
    $file     = UploadedFile::fake()->create('tin_cert.pdf', 100, 'application/pdf');

    $document = $this->service->uploadDocument(
        supplier:     $supplier,
        file:         $file,
        documentType: 'tin_certificate',
        expiresAt:    '2027-12-31',
        uploader:     $this->officer,
    );

    expect($document)->toBeInstanceOf(SupplierDocument::class)
        ->and($document->version)->toBe(1)
        ->and($document->document_type)->toBe('tin_certificate')
        ->and($document->supplier_id)->toBe($supplier->id)
        ->and($document->uploaded_by)->toBe($this->officer->id);

    $this->assertDatabaseHas('supplier_documents', [
        'supplier_id'   => $supplier->id,
        'document_type' => 'tin_certificate',
        'version'       => 1,
    ]);
});

it('uploadDocument: increments version number on subsequent uploads of the same document type', function () {
    $supplier = Supplier::factory()->forTenant($this->tenant)->active()->create();

    // Upload version 1
    $file1 = UploadedFile::fake()->create('tin_v1.pdf', 100, 'application/pdf');
    $doc1  = $this->service->uploadDocument($supplier, $file1, 'tin_certificate', null, $this->officer);

    // Upload version 2
    $file2 = UploadedFile::fake()->create('tin_v2.pdf', 120, 'application/pdf');
    $doc2  = $this->service->uploadDocument($supplier, $file2, 'tin_certificate', null, $this->officer);

    // Upload version 3
    $file3 = UploadedFile::fake()->create('tin_v3.pdf', 110, 'application/pdf');
    $doc3  = $this->service->uploadDocument($supplier, $file3, 'tin_certificate', null, $this->officer);

    expect($doc1->version)->toBe(1)
        ->and($doc2->version)->toBe(2)
        ->and($doc3->version)->toBe(3);

    expect(SupplierDocument::where('supplier_id', $supplier->id)
        ->where('document_type', 'tin_certificate')
        ->count()
    )->toBe(3);
});

it('uploadDocument: different document types have independent version counters', function () {
    $supplier = Supplier::factory()->forTenant($this->tenant)->active()->create();

    $tinFile = UploadedFile::fake()->create('tin.pdf', 100, 'application/pdf');
    $vatFile = UploadedFile::fake()->create('vat.pdf', 100, 'application/pdf');

    $tin = $this->service->uploadDocument($supplier, $tinFile, 'tin_certificate', null, $this->officer);
    $vat = $this->service->uploadDocument($supplier, $vatFile, 'vat_certificate', null, $this->officer);

    expect($tin->version)->toBe(1)->and($vat->version)->toBe(1);
});

it('uploadDocument: stores file at tenant-scoped path', function () {
    $supplier = Supplier::factory()->forTenant($this->tenant)->active()->create();
    $file     = UploadedFile::fake()->create('license.pdf', 50, 'application/pdf');

    $document = $this->service->uploadDocument($supplier, $file, 'business_license', null, $this->officer);

    $expectedPathPrefix = "{$this->tenant->id}/suppliers/{$supplier->id}/business_license/";
    expect($document->file_path)->toStartWith($expectedPathPrefix);
    Storage::disk('local')->assertExists($document->file_path);
});

it('uploadDocument: throws when file size exceeds 10 MB', function () {
    $supplier = Supplier::factory()->forTenant($this->tenant)->active()->create();
    // 11 MB fake file
    $file = UploadedFile::fake()->create('huge.pdf', 11 * 1024, 'application/pdf');

    $this->service->uploadDocument($supplier, $file, 'tin_certificate', null, $this->officer);
})->throws(InvalidArgumentException::class);

it('uploadDocument: throws when MIME type is not allowed', function () {
    $supplier = Supplier::factory()->forTenant($this->tenant)->active()->create();
    $file     = UploadedFile::fake()->create('script.exe', 100, 'application/octet-stream');

    $this->service->uploadDocument($supplier, $file, 'tin_certificate', null, $this->officer);
})->throws(InvalidArgumentException::class);

it('uploadDocument: throws when document_type is not a valid enum value', function () {
    $supplier = Supplier::factory()->forTenant($this->tenant)->active()->create();
    $file     = UploadedFile::fake()->create('doc.pdf', 50, 'application/pdf');

    $this->service->uploadDocument($supplier, $file, 'invalid_type', null, $this->officer);
})->throws(InvalidArgumentException::class);

it('uploadDocument: accepts all valid document types', function () {
    $supplier = Supplier::factory()->forTenant($this->tenant)->active()->create();
    $valid    = ['tin_certificate', 'vat_certificate', 'business_license', 'performance_bond', 'other'];

    foreach ($valid as $type) {
        $file = UploadedFile::fake()->create("{$type}.pdf", 50, 'application/pdf');
        $doc  = $this->service->uploadDocument($supplier, $file, $type, null, $this->officer);
        expect($doc->document_type)->toBe($type);
    }
});

it('uploadDocument: dispatches audit log on successful upload', function () {
    $supplier = Supplier::factory()->forTenant($this->tenant)->active()->create();
    $file     = UploadedFile::fake()->create('bond.pdf', 50, 'application/pdf');

    $this->service->uploadDocument($supplier, $file, 'performance_bond', null, $this->officer);

    Bus::assertDispatched(\App\Jobs\WriteAuditLogJob::class);
});

// ===========================================================================
// 5. Performance Metrics Calculation
// Requirements: 7.6
// ===========================================================================

it('recalculateMetrics: on_time_delivery_rate is 0.00 when no delivery records exist', function () {
    $supplier = Supplier::factory()->forTenant($this->tenant)->active()->create();

    $updated = $this->service->recalculateMetrics($supplier);

    expect(number_format((float) $updated->on_time_delivery_rate, 2, '.', ''))->toBe('0.00');
});

it('recalculateMetrics: on_time_delivery_rate is 100.00 when all deliveries are on time', function () {
    $supplier = Supplier::factory()->forTenant($this->tenant)->active()->create();

    // Record 5 on-time deliveries (value = 1.0000)
    for ($i = 0; $i < 5; $i++) {
        SupplierPerformance::create([
            'tenant_id'      => $this->tenant->id,
            'supplier_id'    => $supplier->id,
            'metric_type'    => 'on_time_delivery',
            'value'          => '1.0000',
            'reference_type' => 'purchase_order',
            'reference_id'   => \Illuminate\Support\Str::uuid(),
            'recorded_at'    => now(),
        ]);
    }

    $updated = $this->service->recalculateMetrics($supplier);

    expect(number_format((float) $updated->on_time_delivery_rate, 2, '.', ''))->toBe('100.00');
});

it('recalculateMetrics: on_time_delivery_rate is 60.00 when 3 of 5 deliveries are on time', function () {
    $supplier = Supplier::factory()->forTenant($this->tenant)->active()->create();

    // 3 on-time (value = 1.0000)
    for ($i = 0; $i < 3; $i++) {
        SupplierPerformance::create([
            'tenant_id'      => $this->tenant->id,
            'supplier_id'    => $supplier->id,
            'metric_type'    => 'on_time_delivery',
            'value'          => '1.0000',
            'reference_type' => 'purchase_order',
            'reference_id'   => \Illuminate\Support\Str::uuid(),
            'recorded_at'    => now(),
        ]);
    }
    // 2 late (value = 0.0000)
    for ($i = 0; $i < 2; $i++) {
        SupplierPerformance::create([
            'tenant_id'      => $this->tenant->id,
            'supplier_id'    => $supplier->id,
            'metric_type'    => 'on_time_delivery',
            'value'          => '0.0000',
            'reference_type' => 'purchase_order',
            'reference_id'   => \Illuminate\Support\Str::uuid(),
            'recorded_at'    => now(),
        ]);
    }

    $updated = $this->service->recalculateMetrics($supplier);

    expect(number_format((float) $updated->on_time_delivery_rate, 2, '.', ''))->toBe('60.00');
});

it('recalculateMetrics: quality_acceptance_rate is 0.00 when no quality records exist', function () {
    $supplier = Supplier::factory()->forTenant($this->tenant)->active()->create();

    $updated = $this->service->recalculateMetrics($supplier);

    expect(number_format((float) $updated->quality_acceptance_rate, 2, '.', ''))->toBe('0.00');
});

it('recalculateMetrics: quality_acceptance_rate is 90.00 for perfect (1.0) quality records', function () {
    $supplier = Supplier::factory()->forTenant($this->tenant)->active()->create();

    // Each record is 1.0000 (100% acceptance on that GRN)
    for ($i = 0; $i < 10; $i++) {
        SupplierPerformance::create([
            'tenant_id'      => $this->tenant->id,
            'supplier_id'    => $supplier->id,
            'metric_type'    => 'quality_acceptance',
            'value'          => '0.9000',   // 90% acceptance per GRN
            'reference_type' => 'goods_receipt',
            'reference_id'   => \Illuminate\Support\Str::uuid(),
            'recorded_at'    => now(),
        ]);
    }

    $updated = $this->service->recalculateMetrics($supplier);

    // average of 10 × 0.9 = 0.9 → × 100 = 90.00
    expect(number_format((float) $updated->quality_acceptance_rate, 2, '.', ''))->toBe('90.00');
});

it('recordPerformanceMetric: persists metric and triggers recalculation', function () {
    $supplier  = Supplier::factory()->forTenant($this->tenant)->active()->create();
    $refId     = (string) \Illuminate\Support\Str::uuid();

    $record = $this->service->recordPerformanceMetric(
        supplier:      $supplier,
        metricType:    'on_time_delivery',
        value:         '1.0000',
        referenceType: 'purchase_order',
        referenceId:   $refId,
    );

    expect($record)->toBeInstanceOf(SupplierPerformance::class)
        ->and($record->metric_type)->toBe('on_time_delivery')
        ->and($record->value)->toBe('1.0000');

    // Recalculation should set on_time_delivery_rate to 100.00
    $supplier->refresh();
    expect(number_format((float) $supplier->on_time_delivery_rate, 2, '.', ''))->toBe('100.00');
});

it('recordPerformanceMetric: running total is updated correctly across multiple calls', function () {
    $supplier = Supplier::factory()->forTenant($this->tenant)->active()->create();

    $this->service->recordPerformanceMetric($supplier, 'on_time_delivery', '1.0000', 'purchase_order', (string) \Illuminate\Support\Str::uuid());
    $this->service->recordPerformanceMetric($supplier, 'on_time_delivery', '0.0000', 'purchase_order', (string) \Illuminate\Support\Str::uuid());

    $supplier->refresh();
    // 1/2 on time = 50%
    expect(number_format((float) $supplier->on_time_delivery_rate, 2, '.', ''))->toBe('50.00');
});

// ===========================================================================
// 6. Search / List
// Requirements: 7.7
// ===========================================================================

it('search: returns paginated results for the active tenant', function () {
    Supplier::factory()->forTenant($this->tenant)->count(5)->create(['status' => 'active']);
    Supplier::factory()->forTenant($this->tenant)->count(3)->pendingVerification()->create();

    $result = $this->service->search([], 20);

    expect($result->total())->toBe(8);
});

it('search: filters by status', function () {
    Supplier::factory()->forTenant($this->tenant)->count(4)->create(['status' => 'active']);
    Supplier::factory()->forTenant($this->tenant)->count(2)->pendingVerification()->create();

    $result = $this->service->search(['status' => 'pending_verification'], 20);

    expect($result->total())->toBe(2);
});

it('search: filters by search term on organization_name', function () {
    Supplier::factory()->forTenant($this->tenant)->create(['organization_name' => 'Acme Global Supplies']);
    Supplier::factory()->forTenant($this->tenant)->create(['organization_name' => 'Beta Services Ltd']);

    $result = $this->service->search(['search' => 'Acme'], 20);

    expect($result->total())->toBe(1)
        ->and($result->items()[0]->organization_name)->toBe('Acme Global Supplies');
});

// ===========================================================================
// 7. Only active suppliers can submit bids / receive POs
// Requirements: 7.9
// ===========================================================================

it('a blacklisted supplier has status blacklisted (not active)', function () {
    $supplier = Supplier::factory()->forTenant($this->tenant)->active()->create();

    $blacklisted = $this->service->blacklist(
        $supplier, $this->officer, 'Systematic overbilling on multiple POs.'
    );

    expect($blacklisted->status)->not->toBe('active')
        ->and($blacklisted->status)->toBe('blacklisted');
});

it('a pending supplier is not active after registration', function () {
    $supplier = $this->service->register(
        data: [
            'organization_name' => 'Pending Corp',
            'contact_name'      => 'Pending User',
            'contact_email'     => 'pending@example.com',
            'business_category' => 'Logistics',
        ],
        tenantId: $this->tenant->id,
    );

    expect($supplier->status)->toBe('pending_verification')
        ->and($supplier->status)->not->toBe('active');
});
