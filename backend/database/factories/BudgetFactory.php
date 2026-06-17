<?php

namespace Database\Factories;

use App\Models\Budget;
use App\Models\Department;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Budget>
 */
class BudgetFactory extends Factory
{
    protected $model = Budget::class;

    public function definition(): array
    {
        $totalAmount = fake()->randomFloat(2, 50000, 5000000);
        $spentPct    = fake()->randomFloat(2, 0, 0.7);
        $encPct      = fake()->randomFloat(2, 0, 0.2);

        $spentAmount      = round($totalAmount * $spentPct, 2);
        $encumberedAmount = round($totalAmount * $encPct, 2);

        return [
            'tenant_id'          => Tenant::factory(),
            'department_id'      => Department::factory(),
            'fiscal_year'        => fake()->year(),
            'currency'           => fake()->randomElement(['USD', 'EUR', 'GBP', 'KES', 'NGN']),
            'total_amount'       => $totalAmount,
            'encumbered_amount'  => $encumberedAmount,
            'spent_amount'       => $spentAmount,
            'created_by'         => User::factory(),
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(['tenant_id' => $tenant->id]);
    }

    public function forDepartment(Department $department): static
    {
        return $this->state([
            'tenant_id'     => $department->tenant_id,
            'department_id' => $department->id,
        ]);
    }

    public function currentYear(): static
    {
        return $this->state(['fiscal_year' => now()->year]);
    }

    public function nearlyExhausted(): static
    {
        return $this->afterMaking(function (Budget $budget) {
            $total = (float) $budget->total_amount;
            $budget->spent_amount      = round($total * 0.85, 2);
            $budget->encumbered_amount = round($total * 0.10, 2);
        });
    }
}
