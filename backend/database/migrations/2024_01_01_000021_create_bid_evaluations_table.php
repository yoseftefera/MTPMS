<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * bid_evaluations records each committee member's score for a bid against
     * a specific evaluation criterion. The composite unique constraint on
     * (tenant_id, bid_id, criteria_id, evaluator_id) ensures each evaluator
     * can submit exactly one score per criterion per bid within a tenant.
     * is_finalized prevents modification after the evaluation round closes.
     * Scores are hidden from other committee members until all have submitted.
     */
    public function up(): void
    {
        Schema::create('bid_evaluations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('bid_id');
            $table->uuid('criteria_id');
            $table->uuid('evaluator_id');
            $table->decimal('score', 5, 2);
            $table->text('comment')->nullable();
            $table->boolean('is_finalized')->default(false);
            $table->timestamps();

            // Foreign keys
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->onDelete('cascade');

            $table->foreign('bid_id')
                ->references('id')
                ->on('bids')
                ->onDelete('cascade');

            $table->foreign('criteria_id')
                ->references('id')
                ->on('bid_evaluation_criteria')
                ->onDelete('restrict');

            $table->foreign('evaluator_id')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');

            // Composite unique: one score per evaluator per criterion per bid within a tenant
            $table->unique(
                ['tenant_id', 'bid_id', 'criteria_id', 'evaluator_id'],
                'bid_evaluations_tenant_bid_criteria_evaluator_unique'
            );

            // Indexes
            $table->index('tenant_id');
            $table->index('bid_id');
            $table->index('criteria_id');
            $table->index('evaluator_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bid_evaluations');
    }
};
