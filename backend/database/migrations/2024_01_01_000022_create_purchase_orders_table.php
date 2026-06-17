<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * purchase_orders represents formal orders issued to suppliers following
     * an approved purchase request or awarded bid. The composite unique
     * constraint on (tenant_id, po_number) ensures PO numbers are unique within
     * a tenant. Status progresses from draft → issued → accepted/rejected →
     * partially_received/fully_received or cancelled/overdue. Soft deletes
     * preserve the audit trail for financial records.
     */
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('po_number', 50);
            $table->uuid('purchase_request_id')->nullable();
            $table->uuid('bid_id')->nullable();
            $table->uuid('supplier_id');
            $table->uuid('department_id');
            $table->enum('status', [
                'draft',
                'issued',
                'accepted',
                'rejected',
                'partially_received',
                'fully_received',
                'cancelled',
                'overdue',
            ])->default('draft');
            $table->decimal('total_amount', 15, 2);
            $table->char('currency', 3)->default('USD');
            $table->text('delivery_address');
            $table->date('required_delivery_date');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->uuid('created_by');
            $table->timestamps();
            $table->softDeletes();

            // Foreign keys
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->onDelete('cascade');

            $table->foreign('purchase_request_id')
                ->references('id')
                ->on('purchase_requests')
                ->onDelete('set null');

            $table->foreign('bid_id')
                ->references('id')
                ->on('bids')
                ->onDelete('set null');

            $table->foreign('supplier_id')
                ->references('id')
                ->on('suppliers')
                ->onDelete('restrict');

            $table->foreign('department_id')
                ->references('id')
                ->on('departments')
                ->onDelete('restrict');

            $table->foreign('created_by')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');

            // Composite unique: PO numbers are unique within a tenant
            $table->unique(['tenant_id', 'po_number'], 'purchase_orders_tenant_po_number_unique');

            // Indexes
            $table->index('tenant_id');
            $table->index('supplier_id');
            $table->index('department_id');
            $table->index('status');
            $table->index('required_delivery_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
