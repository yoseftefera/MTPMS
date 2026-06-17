<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * contract_amendments is an append-only log of changes made to a contract
     * after it becomes active. Each amendment captures a before/after JSON
     * snapshot of the changed fields, providing a full audit trail of contract
     * modifications. The table intentionally omits updated_at — records are
     * never modified after insertion. amendment_number is a sequential counter
     * scoped to each contract.
     */
    public function up(): void
    {
        Schema::create('contract_amendments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('contract_id');
            $table->tinyInteger('amendment_number')->unsigned();
            $table->text('reason');
            $table->json('changes'); // before/after snapshot of modified fields
            $table->uuid('amended_by');
            $table->timestamp('created_at')->useCurrent();

            // Foreign keys
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->onDelete('cascade');

            $table->foreign('contract_id')
                ->references('id')
                ->on('contracts')
                ->onDelete('cascade');

            $table->foreign('amended_by')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');

            // Indexes
            $table->index('tenant_id');
            $table->index('contract_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contract_amendments');
    }
};
