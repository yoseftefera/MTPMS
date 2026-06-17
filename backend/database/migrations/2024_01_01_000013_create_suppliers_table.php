<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * suppliers stores external organizations that can submit bids and receive
     * purchase orders. Each supplier belongs to a tenant and optionally links
     * to a portal user account. Status tracks the verification lifecycle from
     * pending_verification through active, blacklisted, or inactive. Performance
     * metrics (on_time_delivery_rate, quality_acceptance_rate) are updated after
     * each completed transaction.
     */
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id')->nullable();
            $table->string('organization_name', 255);
            $table->string('contact_name', 255);
            $table->string('contact_email', 255);
            $table->string('contact_phone', 50)->nullable();
            $table->string('business_category', 100);
            $table->enum('status', [
                'pending_verification',
                'active',
                'blacklisted',
                'inactive',
            ])->default('pending_verification');
            $table->text('blacklist_reason')->nullable();
            $table->uuid('blacklisted_by')->nullable();
            $table->timestamp('blacklisted_at')->nullable();
            $table->decimal('on_time_delivery_rate', 5, 2)->default(0.00);
            $table->decimal('quality_acceptance_rate', 5, 2)->default(0.00);
            $table->timestamps();
            $table->softDeletes();

            // Foreign keys
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->onDelete('cascade');

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('set null');

            $table->foreign('blacklisted_by')
                ->references('id')
                ->on('users')
                ->onDelete('set null');

            // Indexes
            $table->index('tenant_id');
            $table->index('status');
            $table->index('business_category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
