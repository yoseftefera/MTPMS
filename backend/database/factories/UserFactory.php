<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'tenant_id'             => Tenant::factory(),
            'name'                  => fake()->name(),
            'email'                 => fake()->unique()->safeEmail(),
            'password'              => Hash::make('Password@123'),
            'department_id'         => null,
            'status'                => 'active',
            'failed_login_attempts' => 0,
            'avatar'                => null,
            'phone'                 => fake()->optional(0.6)->phoneNumber(),
            'email_verified_at'     => now(),
        ];
    }

    public function active(): static
    {
        return $this->state(['status' => 'active']);
    }

    public function inactive(): static
    {
        return $this->state(['status' => 'inactive']);
    }

    public function locked(): static
    {
        return $this->state([
            'status'                => 'locked',
            'failed_login_attempts' => 5,
        ]);
    }

    public function unverified(): static
    {
        return $this->state(['email_verified_at' => null]);
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(['tenant_id' => $tenant->id]);
    }
}
