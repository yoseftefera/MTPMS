<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * purchase_request_items holds the individual line items for a PR.
     * Each item captures what is being requested, the quantity, unit of
     * measure, estimated price, and an optional budget code for cost
     * allocation purposes.
     */
    public function up(): void
    {
        Schema::create('purchase_request_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('purchase_request_id');
            $table->string('description', 500);
            $table->decimal('quantity', 15, 2);
            $table->string('unit_of_measure', 50);
            $table->decimal('estimated_unit_price', 15, 2);
            $table->string('budget_code', 50)->nullable();
            $table->timestamps();

            // Foreign keys
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->onDelete('cascade');

            $table->foreign('purchase_request_id')
                ->references('id')
                ->on('purchase_requests')
                ->onDelete('cascade');

            // Indexes
            $table->index('tenant_id');
            $table->index('purchase_request_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_request_items');
    }
};
