<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * bid_documents stores supporting files attached to a bid (technical
     * proposals, financial statements, certifications, etc.). Documents are
     * append-only (only created_at is tracked) and soft-deleted to preserve
     * the audit trail. Bid documents are confidential and must not be visible
     * to other suppliers.
     */
    public function up(): void
    {
        Schema::create('bid_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('bid_id');
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

            $table->foreign('bid_id')
                ->references('id')
                ->on('bids')
                ->onDelete('cascade');

            $table->foreign('uploaded_by')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');

            // Indexes
            $table->index('tenant_id');
            $table->index('bid_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bid_documents');
    }
};
