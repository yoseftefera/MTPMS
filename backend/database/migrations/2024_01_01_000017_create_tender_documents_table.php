<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * tender_documents stores specification files, drawings, terms, and other
     * supporting documents attached to a tender before publication. Documents
     * are append-only (only created_at is tracked) and soft-deleted to preserve
     * the audit trail. Suppliers can download these documents when reviewing
     * a published tender.
     */
    public function up(): void
    {
        Schema::create('tender_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('tender_id');
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

            $table->foreign('tender_id')
                ->references('id')
                ->on('tenders')
                ->onDelete('cascade');

            $table->foreign('uploaded_by')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');

            // Indexes
            $table->index('tenant_id');
            $table->index('tender_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tender_documents');
    }
};
