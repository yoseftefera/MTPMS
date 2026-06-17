<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * budget_transactions records every budget movement (encumber, release,
     * spend, transfer_in, transfer_out) against a specific budget. It uses
     * created_at only — there is no updated_at because transactions are
     * immutable once written.
     */
    public function up(): void
    {
        Schema::create('budget_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('budget_id');
            $table->enum('type', ['encumber', 'release', 'spend', 'transfer_in', 'transfer_out']);
            $table->decimal('amount', 15, 2);
            $table->string('reference_type', 50); // e.g. 'purchase_order', 'invoice'
            $table->uuid('reference_id');          // polymorphic reference UUID
            $table->uuid('created_by');
            $table->timestamp('created_at')->useCurrent();

            // Foreign keys
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->onDelete('cascade');

            $table->foreign('budget_id')
                ->references('id')
                ->on('budgets')
                ->onDelete('cascade');

            $table->foreign('created_by')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');

            // Indexes
            $table->index('tenant_id');
            $table->index('budget_id');
            $table->index('reference_type');
            $table->index('reference_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('budget_transactions');
    }
};
