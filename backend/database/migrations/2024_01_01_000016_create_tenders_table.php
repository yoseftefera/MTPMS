<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * tenders represents formal solicitations published to suppliers inviting
     * bids for goods or services. The composite unique constraint on
     * (tenant_id, reference_number) ensures reference numbers are unique within
     * a tenant. Tender types support open (all active suppliers), restricted
     * (invited suppliers only), and single-source procurement. Status progresses
     * from draft → published → closed → awarded or cancelled.
     */
    public function up(): void
    {
        Schema::create('tenders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('reference_number', 50);
            $table->string('title', 255);
            $table->text('description');
            $table->string('category', 100);
            $table->enum('tender_type', [
                'open',
                'restricted',
                'single_source',
            ])->default('open');
            $table->decimal('estimated_value', 15, 2);
            $table->timestamp('submission_deadline');
            $table->enum('status', [
                'draft',
                'published',
                'closed',
                'awarded',
                'cancelled',
            ])->default('draft');
            $table->uuid('created_by');
            $table->timestamp('published_at')->nullable();
            $table->text('cancellation_reason')->nullable();
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

            // Composite unique: reference numbers are unique within a tenant
            $table->unique(['tenant_id', 'reference_number'], 'tenders_tenant_reference_number_unique');

            // Indexes
            $table->index('tenant_id');
            $table->index('status');
            $table->index('category');
            $table->index('submission_deadline');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenders');
    }
};
