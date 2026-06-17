<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add evaluation-lifecycle columns to the tenders table.
     *
     * evaluation_status tracks the bid evaluation state machine:
     *   pending     — evaluation not yet started (default)
     *   in_progress — at least one score has been submitted
     *   revealed    — all scores submitted and revealed by Procurement_Officer
     *   finalized   — winner selected; evaluation is immutably closed
     *
     * winning_bid_id      — FK to the bid selected as winner
     * winner_justification — mandatory justification text recorded at winner selection
     * evaluation_mode     — 'weighted' (default) or 'price_only' for low-value procurements
     * assigned_evaluators — JSON array of user UUIDs who are assigned to evaluate
     */
    public function up(): void
    {
        Schema::table('tenders', function (Blueprint $table) {
            $table->enum('evaluation_status', [
                'pending',
                'in_progress',
                'revealed',
                'finalized',
            ])->default('pending')->after('status');

            $table->uuid('winning_bid_id')->nullable()->after('evaluation_status');
            $table->text('winner_justification')->nullable()->after('winning_bid_id');
            $table->enum('evaluation_mode', ['weighted', 'price_only'])->default('weighted')->after('winner_justification');
            $table->json('assigned_evaluators')->nullable()->after('evaluation_mode');

            $table->foreign('winning_bid_id')
                ->references('id')
                ->on('bids')
                ->onDelete('set null');

            $table->index('evaluation_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenders', function (Blueprint $table) {
            $table->dropForeign(['winning_bid_id']);
            $table->dropIndex(['evaluation_status']);
            $table->dropColumn([
                'evaluation_status',
                'winning_bid_id',
                'winner_justification',
                'evaluation_mode',
                'assigned_evaluators',
            ]);
        });
    }
};
