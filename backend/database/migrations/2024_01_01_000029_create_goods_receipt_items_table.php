<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * goods_receipt_items records each line item within a goods receipt,
     * linked back to the originating purchase_order_item. Quantities are
     * split into received, accepted, and rejected to support partial
     * acceptance workflows. The rejection_reason captures why items were
     * refused during inspection.
     */
    public function up(): void
    {
        Schema::create('goods_receipt_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('goods_receipt_id');
            $table->uuid('purchase_order_item_id');
            $table->string('description', 500);
            $table->decimal('quantity_received', 15, 2);
            $table->decimal('quantity_accepted', 15, 2)->default(0.00);
            $table->decimal('quantity_rejected', 15, 2)->default(0.00);
            $table->text('rejection_reason')->nullable();
            $table->enum('status', [
                'pending',
                'accepted',
                'rejected',
                'partially_accepted',
            ])->default('pending');
            $table->timestamps();

            // Foreign keys
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->onDelete('cascade');

            $table->foreign('goods_receipt_id')
                ->references('id')
                ->on('goods_receipts')
                ->onDelete('cascade');

            $table->foreign('purchase_order_item_id')
                ->references('id')
                ->on('purchase_order_items')
                ->onDelete('restrict');

            // Indexes
            $table->index('tenant_id');
            $table->index('goods_receipt_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_items');
    }
};
