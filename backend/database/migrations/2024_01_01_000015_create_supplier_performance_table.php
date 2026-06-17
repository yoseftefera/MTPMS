<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * supplier_performance records individual performance metric observations
     * for a supplier. Each row captures a single metric value (e.g., delivery
     * time, quality score) linked to a source document via the polymorphic
     * reference_type / reference_id pair. Aggregate rates on the suppliers
     * table are derived from these raw observations. Only created_at is tracked
     * since performance records are append-only.
     */
    public function up(): void
    {
        Schema::create('supplier_performance', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('supplier_id');
            $table->string('metric_type', 100);
            $table->decimal('value', 8, 4);
            $table->string('reference_type', 50);
            $table->uuid('reference_id');
            $table->timestamp('recorded_at');
            $table->timestamp('created_at')->useCurrent();

            // Foreign keys
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->onDelete('cascade');

            $table->foreign('supplier_id')
                ->references('id')
                ->on('suppliers')
                ->onDelete('cascade');

            // Indexes
            $table->index('tenant_id');
            $table->index('supplier_id');
            $table->index('metric_type');
            $table->index(['reference_type', 'reference_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_performance');
    }
};
