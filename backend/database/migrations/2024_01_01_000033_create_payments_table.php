<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * payments records individual payment transactions against an invoice.
     * Multiple payments can exist per invoice to support partial payment
     * scenarios. Status tracks the payment lifecycle: scheduled → processed
     * or failed. processed_by links to the finance user who executed the
     * payment. payment_reference stores the external transaction reference
     * (e.g., bank transfer ID, cheque number).
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('invoice_id');
            $table->decimal('amount', 15, 2);
            $table->char('currency', 3)->default('USD');
            $table->string('payment_method', 100);
            $table->string('payment_reference', 255)->nullable();
            $table->date('payment_date');
            $table->date('due_date')->nullable();
            $table->enum('status', [
                'scheduled',
                'processed',
                'failed',
            ])->default('scheduled');
            $table->uuid('processed_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // Foreign keys
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->onDelete('cascade');

            $table->foreign('invoice_id')
                ->references('id')
                ->on('invoices')
                ->onDelete('restrict');

            $table->foreign('processed_by')
                ->references('id')
                ->on('users')
                ->onDelete('set null');

            // Indexes
            $table->index('tenant_id');
            $table->index('invoice_id');
            $table->index('status');
            $table->index('payment_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
