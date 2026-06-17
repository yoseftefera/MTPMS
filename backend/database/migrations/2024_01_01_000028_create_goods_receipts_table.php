<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * goods_receipts (GRN — Goods Receipt Notes) records the physical receipt
     * of goods against a purchase order at a specific warehouse. Status
     * progresses from draft → under_inspection → accepted / partially_accepted
     * / rejected. The grn_number is generated per-tenant. No soft deletes —
     * GRNs are immutable once accepted to preserve the receiving audit trail.
     */
    public function up(): void
    {
        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('grn_number', 50);
            $table->uuid('purchase_order_id');
            $table->uuid('warehouse_id');
            $table->string('delivery_note_number', 100);
            $table->enum('status', [
                'draft',
                'under_inspection',
                'accepted',
                'partially_accepted',
                'rejected',
            ])->default('draft');
            $table->uuid('received_by');
            $table->timestamp('received_at');
            $table->timestamps();

            // Foreign keys
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->onDelete('cascade');

            $table->foreign('purchase_order_id')
                ->references('id')
                ->on('purchase_orders')
                ->onDelete('restrict');

            $table->foreign('warehouse_id')
                ->references('id')
                ->on('warehouses')
                ->onDelete('restrict');

            $table->foreign('received_by')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');

            // Indexes
            $table->index('tenant_id');
            $table->index('purchase_order_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('goods_receipts');
    }
};
