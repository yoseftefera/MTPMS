<?php

namespace Database\Factories;

use App\Models\Contract;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contract>
 */
class ContractFactory extends Factory
{
    protected $model = Contract::class;

    private static int $sequence = 1;

    public function definition(): array
    {
        $startDate  = fake()->dateTimeBetween('-2 years', 'now');
        $endDate    = fake()->dateTimeBetween($startDate, '+2 years');
        $totalValue = fake()->randomFloat(2, 10000, 5000000);

        return [
            'tenant_id'          => Tenant::factory(),
            'contract_number'    => 'CTR-' . now()->year . '-' . str_pad(self::$sequence++, 5, '0', STR_PAD_LEFT),
            'purchase_order_id'  => null,
            'tender_id'          => null,
            'supplier_id'        => Supplier::factory(),
            'title'              => fake()->sentence(5),
            'scope'              => fake()->paragraphs(2, true),
            'total_value'        => $totalValue,
            'consumed_value'     => fake()->randomFloat(2, 0, $totalValue * 0.8),
            'currency'           => 'USD',
            'start_date'         => $startDate,
            'end_date'           => $endDate,
            'payment_terms'      => fake()->randomElement([
                'Net 30 days',
                'Net 60 days',
                '50% upfront, 50% on delivery',
                'Monthly installments',
                'Upon completion',
            ]),
            'status'             => fake()->randomElement(['draft', 'pending_bond', 'active', 'expired', 'terminated', 'renewed']),
            'termination_reason' => null,
            'created_by'         => User::factory(),
        ];
    }

    public function active(): static
    {
        return $this->state([
            'status'     => 'active',
            'start_date' => now()->subMonths(3),
            'end_date'   => now()->addMonths(9),
        ]);
    }

    public function expired(): static
    {
        return $this->state([
            'status'     => 'expired',
            'start_date' => now()->subYears(2),
            'end_date'   => now()->subDays(30),
        ]);
    }

    public function expiringSoon(): static
    {
        return $this->state([
            'status'     => 'active',
            'start_date' => now()->subMonths(11),
            'end_date'   => now()->addDays(fake()->numberBetween(1, 60)),
        ]);
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(['tenant_id' => $tenant->id]);
    }
}
