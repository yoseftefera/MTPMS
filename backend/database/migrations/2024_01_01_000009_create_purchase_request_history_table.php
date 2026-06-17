<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * purchase_request_history is an append-only audit trail for every
     * status transition, edit, and comment on a PR. It uses created_at
     * only — there is no updated_at because history records are immutable
     * once written.
     */
    public function up(): void
    {
        Schema::create('purchase_request_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('purchase_request_id');
            $table->string('action', 100);
            $table->string('from_status', 50)->nullable();
            $table->string('to_status', 50)->nullable();
            $table->text('comment')->nullable();
            $table->uuid('performed_by');
            $table->timestamp('created_at')->useCurrent();

            // Foreign keys
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->onDelete('cascade');

            $table->foreign('purchase_request_id')
                ->references('id')
                ->on('purchase_requests')
                ->onDelete('cascade');

            $table->foreign('performed_by')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');

            // Indexes
            $table->index('tenant_id');
            $table->index('purchase_request_id');
            $table->index('performed_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_request_history');
    }
};
