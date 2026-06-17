<?php

/**
 * Unit tests for BidEvaluationService.
 *
 * Covers task 10.1 — BidEvaluationService with:
 *  - Req 9.1  — configurable weighted criteria (weights must sum to 100)
 *  - Req 9.2  — score submission with identity and timestamp recording
 *  - Req 9.3  — weighted score calculation using DECIMAL arithmetic
 *  - Req 9.4  — ranked comparison report (all bids ordered by weighted score)
 *  - Req 9.5  — winner selection with mandatory justification
 *  - Req 9.7  — audit logging of all evaluation actions
 *  - Req 9.8  — score blinding until all evaluators have submitted
 *  - Req 9.9  — reject score modification after finalization; log the attempt
 *  - Req 9.10 — price-only evaluation mode for low-value procurements
 *
 * **Validates: Requirements 9.1, 9.2, 9.3, 9.4, 9.5, 9.7, 9.8, 9.9, 9.10**
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

    $this->tenant = Tenant::factory()->create([
        'status'      => 'active',
        'tenant_code' => 'EVAL',
    ]);

    // Roles
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Procurement_Officer', 'guard_name' => 'api']);
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Committee_Member',    'guard_name' => 'api']);
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Supplier',            'guard_name' => 'api']);

    // Procurement officer
    $this->officer = User::factory()->forTenant($this->tenant)->create(['status' => 'active']);
    $this->officer->assignRole('Procurement_Officer');

    // Two committee members / evaluators
    $this->evaluator1 = User::factory()->forTenant($this->tenant)->create(['status' => 'active']);
    $this->evaluator1->assignRole('Committee_Member');

    $this->evaluator2 = User::factory()->forTenant($this->tenant)->create(['status' => 'active']);
    $this->evaluator2->assignRole('Committee_Member');

    // Two supplier accounts with linked supplier records
    $supplierUser1 = User::factory()->forTenant($this->tenant)->create(['status' => 'active']);
    $supplierUser1->assignRole('Supplier');

    $supplierUser2 = User::factory()->forTenant($this->tenant)->create(['status' => 'active']);
    $supplierUser2->assignRole('Supplier');

    $this->supplier1 = Supplier::factory()->forTenant($this->tenant)->create([
        'user_id'           => $supplierUser1->id,
        'status'            => 'active',
        'organization_name' => 'Alpha Corp',
    ]);

    $this->supplier2 = Supplier::factory()->forTenant($this->tenant)->create([
        'user_id'           => $supplierUser2->id,
        'status'            => 'active',
        'organization_name' => 'Beta Ltd',
    ]);

    // A closed tender with two evaluators assigned
    $this->tender = Tender::factory()->forTenant($this->tenant)->closed()->create([
        'created_by'         => $this->officer->id,
        'assigned_evaluators'=> [$this->evaluator1->id, $this->evaluator2->id],
        'evaluation_mode'    => 'weighted',
    ]);

    // Two submitted bids
    $this->bid1 = Bid::factory()->forTenant($this->tender->tenant)->create([
        'tender_id'   => $this->tender->id,
        'supplier_id' => $this->supplier1->id,
        'total_amount'=> '100000.00',
        'status'      => 'submitted',
    ]);

    $this->bid2 = Bid::factory()->forTenant($this->tender->tenant)->create([
        'tender_id'   => $this->tender->id,
        'supplier_id' => $this->supplier2->id,
        'total_amount'=> '80000.00',
        'status'      => 'submitted',
    ]);

    app()->instance('tenant', $this->tenant);

    $this->service = new BidEvaluationService();
});

// ===========================================================================
// 1. configureCriteria() — Requirement 9.1
// ===========================================================================

it('configureCriteria: creates criteria records when weights sum to exactly 100', function () {
    $this->service->configureCriteria(
        tender:  $this->tender,
        criteria: [
            ['name' => 'Price',             'weight' => 40],
            ['name' => 'Technical Score',   'weight' => 35],
            ['name' => 'Delivery Timeline', 'weight' => 25],
        ],
        actor: $this->officer,
    );

    $this->assertDatabaseCount('bid_evaluation_criteria', 3);

    $this->assertDatabaseHas('bid_evaluation_criteria', [
        'tender_id' => $this->tender->id,
        'name'      => 'Price',
        'weight'    => '40.00',
    ]);
});

it('configureCriteria: throws InvalidArgumentException when weights do not sum to 100', function () {
    $this->service->configureCriteria(
        tender:   $this->tender,
        criteria: [
            ['name' => 'Price',           'weight' => 40],
            ['name' => 'Technical Score', 'weight' => 30],
            // 40 + 30 = 70, not 100
        ],
        actor: $this->officer,
    );
})->throws(InvalidArgumentException::class, '100');

it('configureCriteria: throws InvalidArgumentException when weights sum to more than 100', function () {
    $this->service->configureCriteria(
        tender:   $this->tender,
        criteria: [
            ['name' => 'Price',           'weight' => 60],
            ['name' => 'Technical Score', 'weight' => 50],
            // 60 + 50 = 110
        ],
        actor: $this->officer,
    );
})->throws(InvalidArgumentException::class);

it('configureCriteria: replaces existing criteria on reconfiguration', function () {
    // First configure
    $this->service->configureCriteria(
        tender:   $this->tender,
        criteria: [
            ['name' => 'Price',           'weight' => 60],
            ['name' => 'Technical Score', 'weight' => 40],
        ],
        actor: $this->officer,
    );

    $this->assertDatabaseCount('bid_evaluation_criteria', 2);

    // Reconfigure with 3 criteria
    $this->service->configureCriteria(
        tender:   $this->tender,
        criteria: [
            ['name' => 'Price',             'weight' => 40],
            ['name' => 'Technical Score',   'weight' => 35],
            ['name' => 'Delivery Timeline', 'weight' => 25],
        ],
        actor: $this->officer,
    );

    // Old 2 deleted, new 3 created
    $this->assertDatabaseCount('bid_evaluation_criteria', 3);
    $this->assertDatabaseMissing('bid_evaluation_criteria', ['name' => 'Price', 'weight' => '60.00']);
});

it('configureCriteria: dispatches audit log entry', function () {
    $this->service->configureCriteria(
        tender:   $this->tender,
        criteria: [
            ['name' => 'Price', 'weight' => 100],
        ],
        actor: $this->officer,
    );

    Bus::assertDispatched(\App\Jobs\WriteAuditLogJob::class);
});

it('configureCriteria: throws InvalidArgumentException when criteria array is empty', function () {
    $this->service->configureCriteria(
        tender:   $this->tender,
        criteria: [],
        actor:    $this->officer,
    );
})->throws(InvalidArgumentException::class);

it('configureCriteria: supports decimal weights that sum to 100', function () {
    $this->service->configureCriteria(
        tender:   $this->tender,
        criteria: [
            ['name' => 'Price',             'weight' => 33.34],
            ['name' => 'Technical Score',   'weight' => 33.33],
            ['name' => 'Delivery Timeline', 'weight' => 33.33],
        ],
        actor: $this->officer,
    );

    $this->assertDatabaseCount('bid_evaluation_criteria', 3);
});

// ===========================================================================
// 2. submitScore() — Requirements 9.2, 9.8, 9.9
// ===========================================================================

it('submitScore: creates a BidEvaluation record for valid score 0–100', function () {
    $this->service->configureCriteria(
        tender:   $this->tender,
        criteria: [['name' => 'Price', 'weight' => 100]],
        actor:    $this->officer,
    );

    $criteria = BidEvaluationCriteria::withoutGlobalScopes()
        ->where('tender_id', $this->tender->id)
        ->first();

    $evaluation = $this->service->submitScore(
        criteria:  $criteria,
        bid:       $this->bid1,
        score:     80,
        evaluator: $this->evaluator1,
        actor:     $this->evaluator1,
    );

    expect($evaluation)->toBeInstanceOf(BidEvaluation::class)
        ->and((int) $evaluation->score)->toBe(80)
        ->and($evaluation->evaluator_id)->toBe($this->evaluator1->id);

    $this->assertDatabaseHas('bid_evaluations', [
        'bid_id'       => $this->bid1->id,
        'criteria_id'  => $criteria->id,
        'evaluator_id' => $this->evaluator1->id,
        'score'        => '80.00',
    ]);
});

it('submitScore: accepts boundary score 0', function () {
    $this->service->configureCriteria(
        tender:   $this->tender,
        criteria: [['name' => 'Price', 'weight' => 100]],
        actor:    $this->officer,
    );

    $criteria = BidEvaluationCriteria::withoutGlobalScopes()
        ->where('tender_id', $this->tender->id)->first();

    $evaluation = $this->service->submitScore(
        criteria:  $criteria,
        bid:       $this->bid1,
        score:     0,
        evaluator: $this->evaluator1,
        actor:     $this->evaluator1,
    );

    expect((int) $evaluation->score)->toBe(0);
});

it('submitScore: accepts boundary score 100', function () {
    $this->service->configureCriteria(
        tender:   $this->tender,
        criteria: [['name' => 'Price', 'weight' => 100]],
        actor:    $this->officer,
    );

    $criteria = BidEvaluationCriteria::withoutGlobalScopes()
        ->where('tender_id', $this->tender->id)->first();

    $evaluation = $this->service->submitScore(
        criteria:  $criteria,
        bid:       $this->bid1,
        score:     100,
        evaluator: $this->evaluator1,
        actor:     $this->evaluator1,
    );

    expect((int) $evaluation->score)->toBe(100);
});

it('submitScore: throws InvalidArgumentException for score below 0', function () {
    $this->service->configureCriteria(
        tender:   $this->tender,
        criteria: [['name' => 'Price', 'weight' => 100]],
        actor:    $this->officer,
    );

    $criteria = BidEvaluationCriteria::withoutGlobalScopes()
        ->where('tender_id', $this->tender->id)->first();

    $this->service->submitScore(
        criteria:  $criteria,
        bid:       $this->bid1,
        score:     -1,
        evaluator: $this->evaluator1,
        actor:     $this->evaluator1,
    );
})->throws(InvalidArgumentException::class, '0');

it('submitScore: throws InvalidArgumentException for score above 100', function () {
    $this->service->configureCriteria(
        tender:   $this->tender,
        criteria: [['name' => 'Price', 'weight' => 100]],
        actor:    $this->officer,
    );

    $criteria = BidEvaluationCriteria::withoutGlobalScopes()
        ->where('tender_id', $this->tender->id)->first();

    $this->service->submitScore(
        criteria:  $criteria,
        bid:       $this->bid1,
        score:     101,
        evaluator: $this->evaluator1,
        actor:     $this->evaluator1,
    );
})->throws(InvalidArgumentException::class, '100');

it('submitScore: updates existing score when same evaluator re-submits before finalization', function () {
    $this->service->configureCriteria(
        tender:   $this->tender,
        criteria: [['name' => 'Price', 'weight' => 100]],
        actor:    $this->officer,
    );

    $criteria = BidEvaluationCriteria::withoutGlobalScopes()
        ->where('tender_id', $this->tender->id)->first();

    $this->service->submitScore(
        criteria:  $criteria,
        bid:       $this->bid1,
        score:     70,
        evaluator: $this->evaluator1,
        actor:     $this->evaluator1,
    );

    // Update the score
    $updated = $this->service->submitScore(
        criteria:  $criteria,
        bid:       $this->bid1,
        score:     85,
        evaluator: $this->evaluator1,
        actor:     $this->evaluator1,
    );

    expect((int) $updated->score)->toBe(85);

    // Should still be only 1 record
    $this->assertDatabaseCount('bid_evaluations', 1);
});

it('submitScore: dispatches audit log entry on every submission', function () {
    $this->service->configureCriteria(
        tender:   $this->tender,
        criteria: [['name' => 'Price', 'weight' => 100]],
        actor:    $this->officer,
    );

    $criteria = BidEvaluationCriteria::withoutGlobalScopes()
        ->where('tender_id', $this->tender->id)->first();

    $this->service->submitScore(
        criteria:  $criteria,
        bid:       $this->bid1,
        score:     75,
        evaluator: $this->evaluator1,
        actor:     $this->evaluator1,
    );

    Bus::assertDispatched(\App\Jobs\WriteAuditLogJob::class);
});

it('submitScore: rejects and logs attempt when evaluation is already finalized (Req 9.9)', function () {
    $this->service->configureCriteria(
        tender:   $this->tender,
        criteria: [['name' => 'Price', 'weight' => 100]],
        actor:    $this->officer,
    );

    $criteria = BidEvaluationCriteria::withoutGlobalScopes()
        ->where('tender_id', $this->tender->id)->first();

    // Both evaluators submit for both bids → finalized
    foreach ([$this->bid1, $this->bid2] as $bid) {
        $this->service->submitScore(criteria: $criteria, bid: $bid, score: 80, evaluator: $this->evaluator1, actor: $this->evaluator1);
        $this->service->submitScore(criteria: $criteria, bid: $bid, score: 75, evaluator: $this->evaluator2, actor: $this->evaluator2);
    }

    expect($this->service->isEvaluationFinalized($this->tender->fresh()))->toBeTrue();

    Bus::fake(); // Reset to count only the rejection attempt

    // Attempt to modify a score after finalization
    $this->service->submitScore(
        criteria:  $criteria,
        bid:       $this->bid1,
        score:     90,
        evaluator: $this->evaluator1,
        actor:     $this->evaluator1,
    );
})->throws(InvalidArgumentException::class, 'finalized');

// ===========================================================================
// 3. calculateWeightedScore() — Requirement 9.3
// ===========================================================================

it('calculateWeightedScore: returns null in price-only mode (no criteria)', function () {
    // No criteria configured → price-only mode
    $score = $this->service->calculateWeightedScore($this->bid1);

    expect($score)->toBeNull();
});

it('calculateWeightedScore: throws exception when not all evaluators have submitted', function () {
    $this->service->configureCriteria(
        tender:   $this->tender,
        criteria: [['name' => 'Price', 'weight' => 100]],
        actor:    $this->officer,
    );

    $criteria = BidEvaluationCriteria::withoutGlobalScopes()
        ->where('tender_id', $this->tender->id)->first();

    // Only evaluator1 submits — evaluator2 hasn't
    $this->service->submitScore(
        criteria:  $criteria,
        bid:       $this->bid1,
        score:     80,
        evaluator: $this->evaluator1,
        actor:     $this->evaluator1,
    );

    $this->service->calculateWeightedScore($this->bid1);
})->throws(InvalidArgumentException::class);

it('calculateWeightedScore: correctly computes score for single criterion (100% weight)', function () {
    $this->service->configureCriteria(
        tender:   $this->tender,
        criteria: [['name' => 'Price', 'weight' => 100]],
        actor:    $this->officer,
    );

    $criteria = BidEvaluationCriteria::withoutGlobalScopes()
        ->where('tender_id', $this->tender->id)->first();

    // Both evaluators submit for both bids
    foreach ([$this->bid1, $this->bid2] as $bid) {
        $this->service->submitScore(criteria: $criteria, bid: $bid, score: 80, evaluator: $this->evaluator1, actor: $this->evaluator1);
        $this->service->submitScore(criteria: $criteria, bid: $bid, score: 60, evaluator: $this->evaluator2, actor: $this->evaluator2);
    }

    // avg(80, 60) × 100 / 100 = 70.00
    $score = $this->service->calculateWeightedScore($this->bid1);

    expect($score)->toBe('70.00');
});

it('calculateWeightedScore: correctly applies weights across multiple criteria', function () {
    $this->service->configureCriteria(
        tender:   $this->tender,
        criteria: [
            ['name' => 'Price',           'weight' => 60],
            ['name' => 'Technical Score', 'weight' => 40],
        ],
        actor: $this->officer,
    );

    $criteriaList = BidEvaluationCriteria::withoutGlobalScopes()
        ->where('tender_id', $this->tender->id)
        ->orderBy('name')
        ->get();

    $priceC    = $criteriaList->firstWhere('name', 'Price');
    $technicalC = $criteriaList->firstWhere('name', 'Technical Score');

    // Both evaluators submit for bid1 on both criteria
    foreach ([$this->bid1, $this->bid2] as $bid) {
        // Price criterion: evaluator1=90, evaluator2=70 → avg=80
        $this->service->submitScore(criteria: $priceC,    bid: $bid, score: 90, evaluator: $this->evaluator1, actor: $this->evaluator1);
        $this->service->submitScore(criteria: $priceC,    bid: $bid, score: 70, evaluator: $this->evaluator2, actor: $this->evaluator2);

        // Technical criterion: evaluator1=50, evaluator2=50 → avg=50
        $this->service->submitScore(criteria: $technicalC, bid: $bid, score: 50, evaluator: $this->evaluator1, actor: $this->evaluator1);
        $this->service->submitScore(criteria: $technicalC, bid: $bid, score: 50, evaluator: $this->evaluator2, actor: $this->evaluator2);
    }

    // Expected: (avg_price × 60/100) + (avg_technical × 40/100)
    //         = (80 × 0.6)         + (50 × 0.4)
    //         = 48                 + 20
    //         = 68.00
    $score = $this->service->calculateWeightedScore($this->bid1);

    expect($score)->toBe('68.00');
});

it('calculateWeightedScore: returns result as string with exactly 2 decimal places', function () {
    $this->service->configureCriteria(
        tender:   $this->tender,
        criteria: [['name' => 'Price', 'weight' => 100]],
        actor:    $this->officer,
    );

    $criteria = BidEvaluationCriteria::withoutGlobalScopes()
        ->where('tender_id', $this->tender->id)->first();

    foreach ([$this->bid1, $this->bid2] as $bid) {
        $this->service->submitScore(criteria: $criteria, bid: $bid, score: 100, evaluator: $this->evaluator1, actor: $this->evaluator1);
        $this->service->submitScore(criteria: $criteria, bid: $bid, score: 100, evaluator: $this->evaluator2, actor: $this->evaluator2);
    }

    $score = $this->service->calculateWeightedScore($this->bid1);

    expect($score)->toBeString()
        ->and(preg_match('/^\d+\.\d{2}$/', $score))->toBe(1);
});

// ===========================================================================
// 4. getRankedComparison() — Requirements 9.4, 9.8, 9.10
// ===========================================================================

it('getRankedComparison: returns empty array when tender has no bids', function () {
    $emptyTender = Tender::factory()->forTenant($this->tenant)->closed()->create([
        'created_by'          => $this->officer->id,
        'assigned_evaluators' => [$this->evaluator1->id],
    ]);

    $result = $this->service->getRankedComparison($emptyTender);

    expect($result)->toBe([]);
});

it('getRankedComparison: in price-only mode, ranks bids by total_amount ascending', function () {
    // No criteria configured → price-only mode
    $result = $this->service->getRankedComparison($this->tender);

    expect($result)->toHaveCount(2);

    // bid2 has total_amount=80000 → rank 1 (cheaper = better)
    // bid1 has total_amount=100000 → rank 2
    expect($result[0]['bid_id'])->toBe($this->bid2->id)
        ->and($result[0]['rank'])->toBe(1)
        ->and($result[1]['bid_id'])->toBe($this->bid1->id)
        ->and($result[1]['rank'])->toBe(2);
});

it('getRankedComparison: in price-only mode, weighted_score is null for all entries', function () {
    $result = $this->service->getRankedComparison($this->tender);

    foreach ($result as $row) {
        expect($row['weighted_score'])->toBeNull();
    }
});

it('getRankedComparison: returns weighted_score as null when evaluation not yet finalized (blinding)', function () {
    $this->service->configureCriteria(
        tender:   $this->tender,
        criteria: [['name' => 'Price', 'weight' => 100]],
        actor:    $this->officer,
    );

    $criteria = BidEvaluationCriteria::withoutGlobalScopes()
        ->where('tender_id', $this->tender->id)->first();

    // Only evaluator1 submits — evaluation not yet final
    $this->service->submitScore(criteria: $criteria, bid: $this->bid1, score: 90, evaluator: $this->evaluator1, actor: $this->evaluator1);

    $result = $this->service->getRankedComparison($this->tender);

    foreach ($result as $row) {
        expect($row['weighted_score'])->toBeNull();
    }
});

it('getRankedComparison: returns ranked scores once evaluation is finalized', function () {
    $this->service->configureCriteria(
        tender:   $this->tender,
        criteria: [['name' => 'Price', 'weight' => 100]],
        actor:    $this->officer,
    );

    $criteria = BidEvaluationCriteria::withoutGlobalScopes()
        ->where('tender_id', $this->tender->id)->first();

    // bid1: avg score = (90 + 80) / 2 = 85 → weighted = 85.00
    // bid2: avg score = (70 + 60) / 2 = 65 → weighted = 65.00
    foreach ([$this->bid1, $this->bid2] as $bid) {
        $score1 = ($bid->id === $this->bid1->id) ? 90 : 70;
        $score2 = ($bid->id === $this->bid1->id) ? 80 : 60;
        $this->service->submitScore(criteria: $criteria, bid: $bid, score: $score1, evaluator: $this->evaluator1, actor: $this->evaluator1);
        $this->service->submitScore(criteria: $criteria, bid: $bid, score: $score2, evaluator: $this->evaluator2, actor: $this->evaluator2);
    }

    $result = $this->service->getRankedComparison($this->tender);

    expect($result)->toHaveCount(2)
        ->and($result[0]['bid_id'])->toBe($this->bid1->id)   // higher score → rank 1
        ->and($result[0]['rank'])->toBe(1)
        ->and($result[0]['weighted_score'])->toBe('85.00')
        ->and($result[1]['bid_id'])->toBe($this->bid2->id)   // lower score → rank 2
        ->and($result[1]['rank'])->toBe(2)
        ->and($result[1]['weighted_score'])->toBe('65.00');
});

it('getRankedComparison: each entry contains all required fields', function () {
    $result = $this->service->getRankedComparison($this->tender);

    foreach ($result as $row) {
        expect($row)->toHaveKeys(['bid_id', 'supplier_name', 'total_amount', 'weighted_score', 'rank']);
    }
});

// ===========================================================================
// 5. selectWinner() — Requirements 9.5, 9.6, 9.7
// ===========================================================================

it('selectWinner: marks winning bid as won, others as lost', function () {
    $this->service->selectWinner(
        tender:      $this->tender,
        winningBid:  $this->bid1,
        justification: 'Best price and technical compliance.',
        actor:       $this->officer,
    );

    expect($this->bid1->fresh()->status)->toBe('won')
        ->and($this->bid2->fresh()->status)->toBe('lost');
});

it('selectWinner: stores justification on the tender record', function () {
    $this->service->selectWinner(
        tender:        $this->tender,
        winningBid:    $this->bid1,
        justification: 'Best overall value for money.',
        actor:         $this->officer,
    );

    $this->assertDatabaseHas('tenders', [
        'id'                   => $this->tender->id,
        'winner_justification' => 'Best overall value for money.',
        'winning_bid_id'       => $this->bid1->id,
        'status'               => 'awarded',
    ]);
});

it('selectWinner: throws InvalidArgumentException when justification is empty', function () {
    $this->service->selectWinner(
        tender:        $this->tender,
        winningBid:    $this->bid1,
        justification: '',
        actor:         $this->officer,
    );
})->throws(InvalidArgumentException::class, 'justification');

it('selectWinner: throws InvalidArgumentException when justification is only whitespace', function () {
    $this->service->selectWinner(
        tender:        $this->tender,
        winningBid:    $this->bid1,
        justification: '   ',
        actor:         $this->officer,
    );
})->throws(InvalidArgumentException::class);

it('selectWinner: throws InvalidArgumentException when winning bid does not belong to the tender', function () {
    $otherTender = Tender::factory()->forTenant($this->tenant)->closed()->create([
        'created_by' => $this->officer->id,
    ]);

    $foreignBid = Bid::factory()->forTenant($this->tenant)->create([
        'tender_id'   => $otherTender->id,
        'supplier_id' => $this->supplier1->id,
        'status'      => 'submitted',
    ]);

    $this->service->selectWinner(
        tender:        $this->tender,
        winningBid:    $foreignBid,
        justification: 'Some reason.',
        actor:         $this->officer,
    );
})->throws(InvalidArgumentException::class);

it('selectWinner: dispatches audit log entry', function () {
    Bus::fake();

    $this->service->selectWinner(
        tender:        $this->tender,
        winningBid:    $this->bid1,
        justification: 'Meets all criteria within budget.',
        actor:         $this->officer,
    );

    Bus::assertDispatched(\App\Jobs\WriteAuditLogJob::class);
});

it('selectWinner: dispatches outcome notifications to winning and non-winning suppliers', function () {
    Bus::fake();

    $this->service->selectWinner(
        tender:        $this->tender,
        winningBid:    $this->bid1,
        justification: 'Best technical proposal.',
        actor:         $this->officer,
    );

    Bus::assertDispatched(\App\Jobs\SendBidEvaluationOutcomeJob::class, 2);
});

it('selectWinner: transitions tender status to awarded', function () {
    $this->service->selectWinner(
        tender:        $this->tender,
        winningBid:    $this->bid1,
        justification: 'Lowest compliant bid.',
        actor:         $this->officer,
    );

    expect($this->tender->fresh()->status)->toBe('awarded');
});

// ===========================================================================
// 6. isEvaluationFinalized() — Requirement 9.8
// ===========================================================================

it('isEvaluationFinalized: returns false when no criteria are configured (price-only)', function () {
    expect($this->service->isEvaluationFinalized($this->tender))->toBeFalse();
});

it('isEvaluationFinalized: returns false when no evaluators are assigned', function () {
    $tenderNoEvaluators = Tender::factory()->forTenant($this->tenant)->closed()->create([
        'created_by'          => $this->officer->id,
        'assigned_evaluators' => [],
    ]);

    $this->service->configureCriteria(
        tender:   $tenderNoEvaluators,
        criteria: [['name' => 'Price', 'weight' => 100]],
        actor:    $this->officer,
    );

    expect($this->service->isEvaluationFinalized($tenderNoEvaluators))->toBeFalse();
});

it('isEvaluationFinalized: returns false when not all evaluators have submitted', function () {
    $this->service->configureCriteria(
        tender:   $this->tender,
        criteria: [['name' => 'Price', 'weight' => 100]],
        actor:    $this->officer,
    );

    $criteria = BidEvaluationCriteria::withoutGlobalScopes()
        ->where('tender_id', $this->tender->id)->first();

    // Only evaluator1 submits for bid1
    $this->service->submitScore(criteria: $criteria, bid: $this->bid1, score: 80, evaluator: $this->evaluator1, actor: $this->evaluator1);

    expect($this->service->isEvaluationFinalized($this->tender->fresh()))->toBeFalse();
});

it('isEvaluationFinalized: returns true when all evaluators have submitted for all criteria and bids', function () {
    $this->service->configureCriteria(
        tender:   $this->tender,
        criteria: [['name' => 'Price', 'weight' => 100]],
        actor:    $this->officer,
    );

    $criteria = BidEvaluationCriteria::withoutGlobalScopes()
        ->where('tender_id', $this->tender->id)->first();

    // Both evaluators score both bids
    foreach ([$this->bid1, $this->bid2] as $bid) {
        $this->service->submitScore(criteria: $criteria, bid: $bid, score: 80, evaluator: $this->evaluator1, actor: $this->evaluator1);
        $this->service->submitScore(criteria: $criteria, bid: $bid, score: 75, evaluator: $this->evaluator2, actor: $this->evaluator2);
    }

    expect($this->service->isEvaluationFinalized($this->tender->fresh()))->toBeTrue();
});

it('isEvaluationFinalized: returns false when one bid is missing scores', function () {
    $this->service->configureCriteria(
        tender:   $this->tender,
        criteria: [['name' => 'Price', 'weight' => 100]],
        actor:    $this->officer,
    );

    $criteria = BidEvaluationCriteria::withoutGlobalScopes()
        ->where('tender_id', $this->tender->id)->first();

    // Only bid1 is fully scored; bid2 is missing evaluator2's score
    $this->service->submitScore(criteria: $criteria, bid: $this->bid1, score: 80, evaluator: $this->evaluator1, actor: $this->evaluator1);
    $this->service->submitScore(criteria: $criteria, bid: $this->bid1, score: 75, evaluator: $this->evaluator2, actor: $this->evaluator2);
    $this->service->submitScore(criteria: $criteria, bid: $this->bid2, score: 70, evaluator: $this->evaluator1, actor: $this->evaluator1);
    // evaluator2 hasn't scored bid2 yet

    expect($this->service->isEvaluationFinalized($this->tender->fresh()))->toBeFalse();
});

// ===========================================================================
// 7. configureCriteria() — Requirement 9.9: reject when finalized
// ===========================================================================

it('configureCriteria: throws InvalidArgumentException when evaluation is already finalized', function () {
    $this->service->configureCriteria(
        tender:   $this->tender,
        criteria: [['name' => 'Price', 'weight' => 100]],
        actor:    $this->officer,
    );

    $criteria = BidEvaluationCriteria::withoutGlobalScopes()
        ->where('tender_id', $this->tender->id)->first();

    // Finalize evaluation
    foreach ([$this->bid1, $this->bid2] as $bid) {
        $this->service->submitScore(criteria: $criteria, bid: $bid, score: 80, evaluator: $this->evaluator1, actor: $this->evaluator1);
        $this->service->submitScore(criteria: $criteria, bid: $bid, score: 75, evaluator: $this->evaluator2, actor: $this->evaluator2);
    }

    expect($this->service->isEvaluationFinalized($this->tender->fresh()))->toBeTrue();

    // Attempt to reconfigure after finalization
    $this->service->configureCriteria(
        tender:   $this->tender->fresh(),
        criteria: [
            ['name' => 'New Criterion A', 'weight' => 50],
            ['name' => 'New Criterion B', 'weight' => 50],
        ],
        actor: $this->officer,
    );
})->throws(InvalidArgumentException::class, 'finalized');
