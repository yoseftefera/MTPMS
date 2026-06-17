<?php

namespace Database\Factories;

use App\Models\Supplier;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    private static array $categories = [
        'IT Equipment',
        'Office Supplies',
        'Construction Materials',
        'Medical Supplies',
        'Catering Services',
        'Security Services',
        'Cleaning Services',
        'Consultancy',
        'Transportation',
        'Printing & Stationery',
    ];

    public function definition(): array
    {
        return [
            'tenant_id'                => Tenant::factory(),
            'user_id'                  => null,
            'organization_name'        => fake()->company(),
            'contact_name'             => fake()->name(),
            'contact_email'            => fake()->companyEmail(),
            'contact_phone'            => fake()->optional(0.8)->phoneNumber(),
            'business_category'        => fake()->randomElement(self::$categories),
            'status'                   => 'active',
            'blacklist_reason'         => null,
            'blacklisted_by'           => null,
            'blacklisted_at'           => null,
            'on_time_delivery_rate'    => fake()->randomFloat(2, 60, 100),
            'quality_acceptance_rate'  => fake()->randomFloat(2, 70, 100),
        ];
    }

    public function active(): static
    {
        return $this->state(['status' => 'active']);
    }

    public function pendingVerification(): static
    {
        return $this->state(['status' => 'pending_verification']);
    }

    public function blacklisted(): static
    {
        return $this->state([
            'status'           => 'blacklisted',
            'blacklist_reason' => fake()->sentence(),
            'blacklisted_at'   => now()->subDays(fake()->numberBetween(1, 90)),
        ]);
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(['tenant_id' => $tenant->id]);
    }
}
