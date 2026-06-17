<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * market_research_items stores individual price quotes or supplier
     * responses collected as part of a market research exercise. supplier_id
     * is nullable to allow recording quotes from suppliers not yet registered
     * in the system. estimated_price and currency capture the quoted price
     * for comparison and budget estimation purposes.
     */
    public function up(): void
    {
        Schema::create('market_research_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('market_research_id');
            $table->uuid('supplier_id')->nullable();
            $table->string('item_name', 255);
            $table->text('description')->nullable();
            $table->decimal('estimated_price', 15, 2)->nullable();
            $table->char('currency', 3)->default('USD');
            $table->text('notes')->nullable();
            $table->timestamps();

            // Foreign keys
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->onDelete('cascade');

            $table->foreign('market_research_id')
                ->references('id')
                ->on('market_research')
                ->onDelete('cascade');

            $table->foreign('supplier_id')
                ->references('id')
                ->on('suppliers')
                ->onDelete('set null');

            // Indexes
            $table->index('tenant_id');
            $table->index('market_research_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('market_research_items');
    }
};
