<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * contract_documents stores files attached to a contract (e.g., signed
     * agreement, performance bond, insurance certificates). The table is
     * append-only (created_at only, no updated_at) — documents are uploaded
     * once and soft-deleted if superseded, preserving the full document history
     * for legal and audit purposes.
     */
    public function up(): void
    {
        Schema::create('contract_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('contract_id');
            $table->string('document_type', 100);
            $table->string('file_path', 500);
            $table->string('file_name', 255);
            $table->uuid('uploaded_by');
            $table->timestamp('created_at')->useCurrent();
            $table->softDeletes();

            // Foreign keys
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->onDelete('cascade');

            $table->foreign('contract_id')
                ->references('id')
                ->on('contracts')
                ->onDelete('cascade');

            $table->foreign('uploaded_by')
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
        Schema::dropIfExists('contract_documents');
    }
};
