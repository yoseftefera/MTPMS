<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add fields needed for the PO amendment, rejection, and cancellation workflows.
     *
     * - rejection_reason:                  Supplier's reason when rejecting a PO
     * - cancellation_reason:               Procurement_Officer's reason when cancelling a PO
     * - notes:                             Optional notes/instructions on the PO
     * - pending_supplier_acknowledgment:   Flag set to true when a post-acceptance
     *                                      amendment requires the supplier to re-confirm
     *
     * Requirements: 10.5, 10.9, 10.10
     */
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->text('rejection_reason')->nullable()->after('accepted_at');
            $table->text('cancellation_reason')->nullable()->after('rejection_reason');
            $table->text('notes')->nullable()->after('cancellation_reason');
            $table->boolean('pending_supplier_acknowledgment')->default(false)->after('notes');
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn([
                'rejection_reason',
                'cancellation_reason',
                'notes',
                'pending_supplier_acknowledgment',
            ]);
        });
    }
};
