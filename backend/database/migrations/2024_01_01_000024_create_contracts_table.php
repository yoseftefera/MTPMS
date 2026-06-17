<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * contracts represents legally binding agreements with suppliers, either
     * arising from a purchase order or directly from a tender award. The
     * composite unique constraint on (tenant_id, contract_number) ensures
     * contract numbers are unique within a tenant. consumed_value tracks
     * invoiced amounts against total_value for budget control. Status
     * progresses from draft → pending_bond → active → expired/terminated/renewed.
     * Soft deletes preserve the audit trail for financial and legal records.
     */
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('contract_number', 50);
            $table->uuid('purchase_order_id')->nullable();
            $table->uuid('tender_id')->nullable();
            $table->uuid('supplier_id');
            $table->string('title', 255);
            $table->text('scope');
            $table->decimal('total_value', 15, 2);
            $table->decimal('consumed_value', 15, 2)->default(0.00);
            $table->char('currency', 3)->default('USD');
            $table->date('start_date');
            $table->date('end_date');
            $table->text('payment_terms');
            $table->enum('status', [
                'draft',
                'pending_bond',
                'active',
                'expired',
                'terminated',
                'renewed',
            ])->default('draft');
            $table->text('termination_reason')->nullable();
            $table->uuid('created_by');
            $table->timestamps();
            $table->softDeletes();

            // Foreign keys
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->onDelete('cascade');

            $table->foreign('purchase_order_id')
                ->references('id')
                ->on('purchase_orders')
                ->onDelete('set null');

            $table->foreign('tender_id')
                ->references('id')
                ->on('tenders')
                ->onDelete('set null');

            $table->foreign('supplier_id')
                ->references('id')
                ->on('suppliers')
                ->onDelete('restrict');

            $table->foreign('created_by')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');

            // Composite unique: contract numbers are unique within a tenant
            $table->unique(['tenant_id', 'contract_number'], 'contracts_tenant_contract_number_unique');

            // Indexes
            $table->index('tenant_id');
            $table->index('supplier_id');
            $table->index('status');
            $table->index('end_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
