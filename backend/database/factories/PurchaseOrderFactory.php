<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseOrder>
 */
class PurchaseOrderFactory extends Factory
{
    protected $model = PurchaseOrder::class;

    private static int $sequence = 1;

    public function definition(): array
    {
        $status     = fake()->randomElement(['draft', 'issued', 'accepted', 'rejected', 'partially_received', 'fully_received', 'cancelled', 'overdue']);
        $issuedAt   = in_array($status, ['issued', 'accepted', 'rejected', 'partially_received', 'fully_received', 'overdue'])
            ? fake()->dateTimeBetween('-3 months', '-1 week')
            : null;
        $acceptedAt = in_array($status, ['accepted', 'partially_received', 'fully_received'])
            ? fake()->dateTimeBetween('-2 months', '-1 day')
            : null;

        return [
            'tenant_id'              => Tenant::factory(),
            'po_number'              => 'PO-DEMO-' . now()->year . '-' . str_pad(self::$sequence++, 5, '0', STR_PAD_LEFT),
            'purchase_request_id'    => null,
            'bid_id'                 => null,
            'supplier_id'            => Supplier::factory(),
            'department_id'          => Department::factory(),
            'status'                 => $status,
            'total_amount'           => fake()->randomFloat(2, 1000, 1000000),
            'currency'               => 'USD',
            'delivery_address'       => fake()->address(),
            'required_delivery_date' => fake()->dateTimeBetween('now', '+90 days'),
            'issued_at'              => $issuedAt,
            'accepted_at'            => $acceptedAt,
            'created_by'             => User::factory(),
        ];
    }

    public function draft(): static
    {
        return $this->state([
            'status'      => 'draft',
            'issued_at'   => null,
            'accepted_at' => null,
        ]);
    }

    public function issued(): static
    {
        return $this->state([
            'status'    => 'issued',
            'issued_at' => now()->subDays(fake()->numberBetween(1, 14)),
        ]);
    }

    public function accepted(): static
    {
        return $this->state([
            'status'      => 'accepted',
            'issued_at'   => now()->subDays(10),
            'accepted_at' => now()->subDays(7),
        ]);
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(['tenant_id' => $tenant->id]);
    }
}
