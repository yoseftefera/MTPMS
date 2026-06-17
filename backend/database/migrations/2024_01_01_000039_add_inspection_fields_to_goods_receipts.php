<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add assigned_inspectors (committee) and pending_inspection status to
     * goods_receipts, and add inspection_votes JSON to goods_receipt_items.
     *
     * - assigned_inspectors: JSON array of User UUIDs designated by the Store_Manager
     * - status gains 'pending_inspection' to represent GRNs awaiting committee assignment
     * - goods_receipt_items.inspection_votes: JSON map { user_id => {accepted, notes} }
     *
     * Requirements: 12.2, 12.3
     */
    public function up(): void
    {
        // Modify goods_receipts: add assigned_inspectors column and expand status enum
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->json('assigned_inspectors')->nullable()->after('received_at');
        });

        // Modify goods_receipt_items: add inspection_votes column
        Schema::table('goods_receipt_items', function (Blueprint $table) {
            $table->json('inspection_votes')->nullable()->after('status');
        });

        // MySQL requires raw ALTER to modify an ENUM column
        DB::statement(
            "ALTER TABLE goods_receipts MODIFY COLUMN status ENUM(
                'draft',
                'pending_inspection',
                'under_inspection',
                'accepted',
                'partially_accepted',
                'rejected'
            ) NOT NULL DEFAULT 'draft'"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->dropColumn('assigned_inspectors');
        });

        Schema::table('goods_receipt_items', function (Blueprint $table) {
            $table->dropColumn('inspection_votes');
        });

        DB::statement(
            "ALTER TABLE goods_receipts MODIFY COLUMN status ENUM(
                'draft',
                'under_inspection',
                'accepted',
                'partially_accepted',
                'rejected'
            ) NOT NULL DEFAULT 'draft'"
        );
    }
};
