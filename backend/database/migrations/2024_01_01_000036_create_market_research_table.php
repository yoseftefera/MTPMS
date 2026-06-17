<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * market_research stores market price surveys and research exercises
     * initiated by procurement staff before raising a purchase request or
     * tender. Status progresses from draft → published → closed. Soft
     * deletes preserve historical research records for reporting and
     * compliance purposes.
     */
    public function up(): void
    {
        Schema::create('market_research', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('category', 100);
            $table->enum('status', [
                'draft',
                'published',
                'closed',
            ])->default('draft');
            $table->uuid('created_by');
            $table->timestamps();
            $table->softDeletes();

            // Foreign keys
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->onDelete('cascade');

            $table->foreign('created_by')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');

            // Indexes
            $table->index('tenant_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('market_research');
    }
};
