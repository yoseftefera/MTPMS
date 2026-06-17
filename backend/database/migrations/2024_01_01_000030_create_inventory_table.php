<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * inventory tracks stock levels per item per warehouse per tenant.
     * The composite unique constraint on (tenant_id, warehouse_id, item_code)
     * ensures each item code is unique within a warehouse for a given tenant,
     * preventing duplicate stock records. current_stock is updated when goods
     * receipts are accepted. reorder_threshold triggers low-stock notifications.
     */
    public function up(): void
    {
        Schema::create('inventory', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('warehouse_id');
            $table->string('item_code', 100);
            $table->string('item_name', 255);
            $table->string('category', 100);
            $table->string('unit_of_measure', 50);
            $table->decimal('current_stock', 15, 2)->default(0.00);
            $table->decimal('reorder_threshold', 15, 2)->default(0.00);
            $table->decimal('unit_cost', 15, 2)->default(0.00);
            $table->timestamps();

            // Foreign keys
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->onDelete('cascade');

            $table->foreign('warehouse_id')
                ->references('id')
                ->on('warehouses')
                ->onDelete('restrict');

            // Composite unique: item codes are unique per warehouse per tenant
            $table->unique(
                ['tenant_id', 'warehouse_id', 'item_code'],
                'inventory_tenant_warehouse_item_code_unique'
            );

            // Indexes
            $table->index('tenant_id');
            $table->index('warehouse_id');
            $table->index('category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory');
    }
};
