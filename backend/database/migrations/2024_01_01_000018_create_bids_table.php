<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * bids records a supplier's formal response to a tender. The composite
     * unique constraint on (tenant_id, tender_id, supplier_id) enforces that
     * each supplier can submit exactly one bid per tender within a tenant.
     * weighted_score is populated after bid evaluation is complete. Status
     * progresses from draft → submitted → under_evaluation → won/lost/disqualified.
     */
    public function up(): void
    {
        Schema::create('bids', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('tender_id');
            $table->uuid('supplier_id');
            $table->decimal('total_amount', 15, 2);
            $table->char('currency', 3)->default('USD');
            $table->smallInteger('delivery_days')->unsigned();
            $table->text('technical_notes')->nullable();
            $table->enum('status', [
                'draft',
                'submitted',
                'under_evaluation',
                'won',
                'lost',
                'disqualified',
            ])->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->decimal('weighted_score', 8, 4)->nullable();
            $table->timestamps();

            // Foreign keys
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->onDelete('cascade');

            $table->foreign('tender_id')
                ->references('id')
                ->on('tenders')
                ->onDelete('restrict');

            $table->foreign('supplier_id')
                ->references('id')
                ->on('suppliers')
                ->onDelete('restrict');

            // Composite unique: one bid per supplier per tender within a tenant
            $table->unique(
                ['tenant_id', 'tender_id', 'supplier_id'],
                'bids_tenant_tender_supplier_unique'
            );

            // Indexes
            $table->index('tenant_id');
            $table->index('tender_id');
            $table->index('supplier_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bids');
    }
};
