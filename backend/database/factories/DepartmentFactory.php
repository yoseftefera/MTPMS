<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    private static array $departmentNames = [
        'Finance',
        'Human Resources',
        'Information Technology',
        'Operations',
        'Procurement',
        'Legal',
        'Marketing',
        'Administration',
        'Engineering',
        'Logistics',
        'Quality Assurance',
        'Research & Development',
    ];

    public function definition(): array
    {
        $name = fake()->unique()->randomElement(self::$departmentNames) . ' ' . fake()->numerify('##');

        return [
            'tenant_id' => Tenant::factory(),
            'name'      => $name,
            'code'      => strtoupper(Str::limit(Str::slug($name, ''), 8, '')) . fake()->numerify('##'),
            'parent_id' => null,
            'status'    => 'active',
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

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(['tenant_id' => $tenant->id]);
    }

    public function childOf(Department $parent): static
    {
        return $this->state([
            'tenant_id' => $parent->tenant_id,
            'parent_id' => $parent->id,
        ]);
    }
}
