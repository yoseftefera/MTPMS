<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * approval_workflow_levels defines each sequential step within an
     * approval workflow. Each level specifies whether approval is by role
     * or by a named user, supports parallel approval (all approvers must
     * act), and configures an escalation timeout in hours.
     */
    public function up(): void
    {
        Schema::create('approval_workflow_levels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('workflow_id');
            $table->tinyInteger('level_order')->unsigned(); // 1–10
            $table->enum('approver_type', ['role', 'user']);
            $table->string('approver_role', 100)->nullable();
            $table->uuid('approver_user_id')->nullable();
            $table->boolean('is_parallel')->default(false);
            $table->smallInteger('escalation_hours')->unsigned()->default(48);
            $table->timestamps();

            // Foreign keys
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->onDelete('cascade');

            $table->foreign('workflow_id')
                ->references('id')
                ->on('approval_workflows')
                ->onDelete('cascade');

            $table->foreign('approver_user_id')
                ->references('id')
                ->on('users')
                ->onDelete('set null');

            // Indexes
            $table->index('tenant_id');
            $table->index('workflow_id');
            $table->index('approver_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approval_workflow_levels');
    }
};
