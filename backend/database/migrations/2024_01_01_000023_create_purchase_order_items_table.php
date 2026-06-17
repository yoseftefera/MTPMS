<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * purchase_order_items stores the line items for each purchase order.
     * received_quantity tracks partial deliveries against the ordered quantity,
     * enabling the parent PO status to transition between partially_received
     * and fully_received as goods receipts are recorded.
     */
    public function up(): void
    {
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('purchase_order_id');
            $table->string('description', 500);
            $table->decimal('quantity', 15, 2);
            $table->decimal('received_quantity', 15, 2)->default(0.00);
            $table->string('unit_of_measure', 50);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('total_price', 15, 2);
            $table->timestamps();

            // Foreign keys
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->onDelete('cascade');

            $table->foreign('purchase_order_id')
                ->references('id')
                ->on('purchase_orders')
                ->onDelete('cascade');

            // Indexes
            $table->index('tenant_id');
            $table->index('purchase_order_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
    }
};
