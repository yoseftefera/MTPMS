<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\PurchaseRequest;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseRequest>
 */
class PurchaseRequestFactory extends Factory
{
    protected $model = PurchaseRequest::class;

    private static int $sequence = 1;

    public function definition(): array
    {
        $status      = fake()->randomElement(['draft', 'pending_approval', 'approved', 'rejected', 'revision_required', 'cancelled']);
        $submittedAt = in_array($status, ['pending_approval', 'approved', 'rejected', 'revision_required', 'cancelled'])
            ? fake()->dateTimeBetween('-6 months', 'now')
            : null;

        return [
            'tenant_id'       => Tenant::factory(),
            'pr_number'       => 'PR-DEMO-' . now()->year . '-' . str_pad(self::$sequence++, 5, '0', STR_PAD_LEFT),
            'department_id'   => Department::factory(),
            'submitted_by'    => User::factory(),
            'status'          => $status,
            'title'           => fake()->sentence(4),
            'description'     => fake()->optional(0.8)->paragraph(),
            'estimated_total' => fake()->randomFloat(2, 500, 500000),
            'currency'        => 'USD',
            'required_date'   => fake()->optional(0.7)->dateTimeBetween('now', '+6 months'),
            'submitted_at'    => $submittedAt,
        ];
    }

    public function draft(): static
    {
        return $this->state([
            'status'       => 'draft',
            'submitted_at' => null,
        ]);
    }

    public function pendingApproval(): static
    {
        return $this->state([
            'status'       => 'pending_approval',
            'submitted_at' => now()->subHours(fake()->numberBetween(1, 72)),
        ]);
    }

    public function approved(): static
    {
        return $this->state([
            'status'       => 'approved',
            'submitted_at' => now()->subDays(fake()->numberBetween(1, 30)),
        ]);
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(['tenant_id' => $tenant->id]);
    }
}
