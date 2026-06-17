<?php

namespace Database\Factories;

use App\Models\Bid;
use App\Models\Supplier;
use App\Models\Tender;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bid>
 */
class BidFactory extends Factory
{
    protected $model = Bid::class;

    public function definition(): array
    {
        $status      = fake()->randomElement(['draft', 'submitted', 'under_evaluation', 'won', 'lost', 'disqualified']);
        $submittedAt = $status !== 'draft'
            ? fake()->dateTimeBetween('-2 months', '-1 day')
            : null;

        return [
            'tenant_id'      => Tenant::factory(),
            'tender_id'      => Tender::factory(),
            'supplier_id'    => Supplier::factory(),
            'total_amount'   => fake()->randomFloat(2, 5000, 1500000),
            'currency'       => 'USD',
            'delivery_days'  => fake()->numberBetween(7, 90),
            'technical_notes' => fake()->optional(0.7)->paragraph(),
            'status'         => $status,
            'submitted_at'   => $submittedAt,
            'weighted_score' => in_array($status, ['under_evaluation', 'won', 'lost'])
                ? fake()->randomFloat(4, 40, 100)
                : null,
        ];
    }

    public function submitted(): static
    {
        return $this->state([
            'status'       => 'submitted',
            'submitted_at' => now()->subDays(fake()->numberBetween(1, 14)),
        ]);
    }

    public function won(): static
    {
        return $this->state([
            'status'         => 'won',
            'submitted_at'   => now()->subDays(30),
            'weighted_score' => fake()->randomFloat(4, 80, 100),
        ]);
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(['tenant_id' => $tenant->id]);
    }
}
