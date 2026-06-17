<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('department_id');
            $table->year('fiscal_year');
            $table->char('currency', 3)->default('USD');
            $table->decimal('total_amount', 15, 2);
            $table->decimal('encumbered_amount', 15, 2)->default(0.00);
            $table->decimal('spent_amount', 15, 2)->default(0.00);
            $table->uuid('created_by');
            $table->timestamps();

            // Foreign keys
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->onDelete('cascade');

            $table->foreign('department_id')
                ->references('id')
                ->on('departments')
                ->onDelete('cascade');

            $table->foreign('created_by')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');

            // Composite unique: one budget per department per fiscal year per tenant
            $table->unique(
                ['tenant_id', 'department_id', 'fiscal_year'],
                'budgets_tenant_dept_year_unique'
            );

            // Indexes
            $table->index('tenant_id');
            $table->index('department_id');
            $table->index('fiscal_year');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};
