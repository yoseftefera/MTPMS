<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\Supplier;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    private static int $sequence = 1;

    public function definition(): array
    {
        $totalAmount  = fake()->randomFloat(2, 500, 500000);
        $status       = fake()->randomElement(['submitted', 'under_review', 'approved', 'rejected', 'paid', 'partially_paid', 'cancelled']);
        $paidAmount   = in_array($status, ['paid']) ? $totalAmount : (
            $status === 'partially_paid' ? fake()->randomFloat(2, 100, $totalAmount * 0.9) : 0
        );
        $invoiceDate  = fake()->dateTimeBetween('-6 months', 'now');
        $dueDate      = fake()->dateTimeBetween($invoiceDate, '+60 days');

        return [
            'tenant_id'          => Tenant::factory(),
            'invoice_number'     => 'INV-' . now()->year . '-' . str_pad(self::$sequence++, 6, '0', STR_PAD_LEFT),
            'supplier_id'        => Supplier::factory(),
            'purchase_order_id'  => null,
            'contract_id'        => null,
            'total_amount'       => $totalAmount,
            'paid_amount'        => $paidAmount,
            'currency'           => 'USD',
            'invoice_date'       => $invoiceDate,
            'due_date'           => $dueDate,
            'status'             => $status,
            'rejection_reason'   => $status === 'rejected' ? fake()->sentence() : null,
            'submitted_at'       => $invoiceDate,
        ];
    }

    public function submitted(): static
    {
        return $this->state([
            'status'      => 'submitted',
            'paid_amount' => 0,
        ]);
    }

    public function approved(): static
    {
        return $this->state([
            'status'      => 'approved',
            'paid_amount' => 0,
        ]);
    }

    public function paid(): static
    {
        return $this->afterMaking(function (Invoice $invoice) {
            $invoice->status      = 'paid';
            $invoice->paid_amount = $invoice->total_amount;
        });
    }

    public function overdue(): static
    {
        return $this->state([
            'status'   => 'approved',
            'due_date' => now()->subDays(fake()->numberBetween(1, 30)),
        ]);
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(['tenant_id' => $tenant->id]);
    }
}
