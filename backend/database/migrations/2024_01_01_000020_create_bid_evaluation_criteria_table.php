<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * bid_evaluation_criteria defines the weighted scoring model for a tender.
     * The Procurement_Officer configures criteria (e.g., price, technical
     * compliance, delivery time) and assigns a percentage weight to each.
     * All weights for a tender must sum to 100. max_score defines the upper
     * bound for scores submitted by committee members against each criterion.
     */
    public function up(): void
    {
        Schema::create('bid_evaluation_criteria', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('tender_id');
            $table->string('name', 255);
            $table->decimal('weight', 5, 2);
            $table->decimal('max_score', 5, 2)->default(100.00);
            $table->text('description')->nullable();
            $table->timestamps();

            // Foreign keys
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->onDelete('cascade');

            $table->foreign('tender_id')
                ->references('id')
                ->on('tenders')
                ->onDelete('cascade');

            // Indexes
            $table->index('tenant_id');
            $table->index('tender_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bid_evaluation_criteria');
    }
};
