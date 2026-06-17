<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * approvals records every individual approval action taken against a
     * document at a specific workflow level. The document_type + document_id
     * pair is a polymorphic reference allowing approvals to be linked to any
     * approvable entity (purchase_request, tender, purchase_order, etc.).
     */
    public function up(): void
    {
        Schema::create('approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('workflow_id');
            $table->uuid('level_id');
            $table->string('document_type', 50);
            $table->uuid('document_id');
            $table->uuid('approver_id');
            $table->enum('action', ['pending', 'approved', 'rejected', 'returned'])->default('pending');
            $table->text('comment')->nullable();
            $table->timestamp('acted_at')->nullable();
            $table->timestamps();

            // Foreign keys
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->onDelete('cascade');

            $table->foreign('workflow_id')
                ->references('id')
                ->on('approval_workflows')
                ->onDelete('restrict');

            $table->foreign('level_id')
                ->references('id')
                ->on('approval_workflow_levels')
                ->onDelete('restrict');

            $table->foreign('approver_id')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');

            // Indexes
            $table->index('tenant_id');
            $table->index('document_type');
            $table->index('document_id');
            $table->index('approver_id');
            $table->index('action');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approvals');
    }
};
