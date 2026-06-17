<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * supplier_documents stores compliance documents uploaded by or on behalf
     * of a supplier (TIN certificate, VAT certificate, business license, etc.).
     * The version column supports document versioning — all historical versions
     * are retained via soft deletes. Only created_at is tracked (no updated_at)
     * since documents are immutable once uploaded; a new version is created
     * instead of updating an existing record.
     */
    public function up(): void
    {
        Schema::create('supplier_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('supplier_id');
            $table->enum('document_type', [
                'tin_certificate',
                'vat_certificate',
                'business_license',
                'performance_bond',
                'other',
            ]);
            $table->string('file_path', 500);
            $table->string('file_name', 255);
            $table->date('expires_at')->nullable();
            $table->tinyInteger('version')->unsigned()->default(1);
            $table->uuid('uploaded_by');
            $table->timestamp('created_at')->useCurrent();
            $table->softDeletes();

            // Foreign keys
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->onDelete('cascade');

            $table->foreign('supplier_id')
                ->references('id')
                ->on('suppliers')
                ->onDelete('cascade');

            $table->foreign('uploaded_by')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');

            // Indexes
            $table->index('tenant_id');
            $table->index('supplier_id');
            $table->index('document_type');
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_documents');
    }
};
