<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * invoices records supplier invoices submitted for payment against a
     * purchase order or contract. The composite unique on (tenant_id,
     * invoice_number) prevents duplicate invoice submissions per tenant.
     * paid_amount tracks partial payments; status reflects the full payment
     * lifecycle from submission through approval to payment. Soft deletes
     * preserve the financial audit trail.
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('invoice_number', 100);
            $table->uuid('supplier_id');
            $table->uuid('purchase_order_id')->nullable();
            $table->uuid('contract_id')->nullable();
            $table->decimal('total_amount', 15, 2);
            $table->decimal('paid_amount', 15, 2)->default(0.00);
            $table->char('currency', 3)->default('USD');
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->enum('status', [
                'submitted',
                'under_review',
                'approved',
                'rejected',
                'paid',
                'partially_paid',
                'cancelled',
            ])->default('submitted');
            $table->text('rejection_reason')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamps();
            $table->softDeletes();

            // Foreign keys
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->onDelete('cascade');

            $table->foreign('supplier_id')
                ->references('id')
                ->on('suppliers')
                ->onDelete('restrict');

            $table->foreign('purchase_order_id')
                ->references('id')
                ->on('purchase_orders')
                ->onDelete('set null');

            $table->foreign('contract_id')
                ->references('id')
                ->on('contracts')
                ->onDelete('set null');

            // Composite unique: invoice numbers are unique within a tenant
            $table->unique(['tenant_id', 'invoice_number'], 'invoices_tenant_invoice_number_unique');

            // Indexes
            $table->index('tenant_id');
            $table->index('supplier_id');
            $table->index('status');
            $table->index('due_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
