<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        $status      = fake()->randomElement(['scheduled', 'processed', 'failed']);
        $paymentDate = fake()->dateTimeBetween('-3 months', '+30 days');

        return [
            'tenant_id'         => Tenant::factory(),
            'invoice_id'        => Invoice::factory(),
            'amount'            => fake()->randomFloat(2, 100, 200000),
            'currency'          => 'USD',
            'payment_method'    => fake()->randomElement([
                'Bank Transfer',
                'Cheque',
                'RTGS',
                'EFT',
                'Mobile Money',
            ]),
            'payment_reference' => strtoupper(fake()->bothify('PAY-####-????')),
            'payment_date'      => $paymentDate,
            'due_date'          => fake()->optional(0.7)->dateTimeBetween($paymentDate, '+30 days'),
            'status'            => $status,
            'processed_by'      => $status === 'processed' ? User::factory() : null,
            'notes'             => fake()->optional(0.4)->sentence(),
        ];
    }

    public function scheduled(): static
    {
        return $this->state([
            'status'       => 'scheduled',
            'payment_date' => fake()->dateTimeBetween('now', '+30 days'),
            'processed_by' => null,
        ]);
    }

    public function processed(): static
    {
        return $this->state([
            'status'       => 'processed',
            'payment_date' => fake()->dateTimeBetween('-3 months', 'now'),
            'processed_by' => User::factory(),
        ]);
    }

    public function failed(): static
    {
        return $this->state([
            'status'       => 'failed',
            'processed_by' => null,
        ]);
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(['tenant_id' => $tenant->id]);
    }
}
