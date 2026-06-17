<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * warehouses represents physical storage locations owned by a tenant.
     * Each warehouse has a short code that is unique within the tenant
     * (composite unique on tenant_id, code). Warehouses are referenced by
     * goods_receipts and inventory records, so this table must exist before
     * those tables are created.
     */
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name', 255);
            $table->string('code', 50);
            $table->text('location')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Foreign keys
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->onDelete('cascade');

            // Composite unique: warehouse codes are unique within a tenant
            $table->unique(['tenant_id', 'code'], 'warehouses_tenant_code_unique');

            // Indexes
            $table->index('tenant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};
