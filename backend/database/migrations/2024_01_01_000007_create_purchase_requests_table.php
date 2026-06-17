<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * purchase_requests is the core procurement document. Each PR belongs to
     * a tenant and department, is submitted by a user, and progresses through
     * a configurable approval workflow. The composite unique constraint on
     * (tenant_id, pr_number) ensures PR numbers are unique within a tenant.
     */
    public function up(): void
    {
        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('pr_number', 50);
            $table->uuid('department_id');
            $table->uuid('submitted_by');
            $table->enum('status', [
                'draft',
                'pending_approval',
                'approved',
                'rejected',
                'revision_required',
                'cancelled',
            ])->default('draft');
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->decimal('estimated_total', 15, 2);
            $table->char('currency', 3)->default('USD');
            $table->date('required_date')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Foreign keys
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->onDelete('cascade');

            $table->foreign('department_id')
                ->references('id')
                ->on('departments')
                ->onDelete('restrict');

            $table->foreign('submitted_by')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');

            // Composite unique: PR numbers are unique within a tenant
            $table->unique(['tenant_id', 'pr_number'], 'purchase_requests_tenant_pr_number_unique');

            // Indexes
            $table->index('tenant_id');
            $table->index('department_id');
            $table->index('submitted_by');
            $table->index('status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_requests');
    }
};
