<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Departments must be created before users because users have a nullable
     * FK to departments(id). The self-referencing parent_id FK is added after
     * the table is created to avoid a forward-reference issue.
     */
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name', 255);
            $table->string('code', 20);
            $table->uuid('parent_id')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
            $table->softDeletes();

            // Foreign keys
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->onDelete('cascade');

            // Self-referencing FK for parent department
            $table->foreign('parent_id')
                ->references('id')
                ->on('departments')
                ->onDelete('set null');

            // Composite unique: department code must be unique within a tenant
            $table->unique(['tenant_id', 'code'], 'departments_tenant_code_unique');

            // Indexes
            $table->index('tenant_id');
            $table->index('status');
            $table->index('parent_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
