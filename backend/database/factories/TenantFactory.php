<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        $name = fake()->company();
        $subdomain = Str::slug($name) . '-' . fake()->numerify('###');

        return [
            'name'         => $name,
            'subdomain'    => $subdomain,
            'admin_email'  => fake()->companyEmail(),
            'status'       => Tenant::STATUS_ACTIVE,
            'tenant_code'  => strtoupper(Str::random(6)),
            'settings'     => [
                'password_min_length'    => 8,
                'session_timeout_minutes' => 60,
                'max_failed_logins'      => 5,
                'approval_workflow_depth' => 5,
            ],
        ];
    }

    public function active(): static
    {
        return $this->state(['status' => Tenant::STATUS_ACTIVE]);
    }

    public function suspended(): static
    {
        return $this->state(['status' => Tenant::STATUS_SUSPENDED]);
    }

    public function deactivated(): static
    {
        return $this->state(['status' => Tenant::STATUS_DEACTIVATED]);
    }
}
