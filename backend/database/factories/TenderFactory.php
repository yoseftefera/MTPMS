<?php

namespace Database\Factories;

use App\Models\Tender;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tender>
 */
class TenderFactory extends Factory
{
    protected $model = Tender::class;

    private static int $sequence = 1;

    private static array $categories = [
        'IT Equipment',
        'Office Supplies',
        'Construction',
        'Medical Supplies',
        'Catering',
        'Security',
        'Consultancy',
        'Transportation',
    ];

    public function definition(): array
    {
        $status      = fake()->randomElement(['draft', 'published', 'closed', 'awarded', 'cancelled']);
        $publishedAt = in_array($status, ['published', 'closed', 'awarded'])
            ? fake()->dateTimeBetween('-3 months', '-1 week')
            : null;

        $deadline = $status === 'published'
            ? fake()->dateTimeBetween('now', '+30 days')
            : fake()->dateTimeBetween('-2 months', '-1 day');

        return [
            'tenant_id'           => Tenant::factory(),
            'reference_number'    => 'TND-' . now()->year . '-' . str_pad(self::$sequence++, 5, '0', STR_PAD_LEFT),
            'title'               => fake()->sentence(5),
            'description'         => fake()->paragraphs(2, true),
            'category'            => fake()->randomElement(self::$categories),
            'tender_type'         => fake()->randomElement(['open', 'restricted', 'single_source']),
            'estimated_value'     => fake()->randomFloat(2, 10000, 2000000),
            'submission_deadline' => $deadline,
            'status'              => $status,
            'created_by'          => User::factory(),
            'published_at'        => $publishedAt,
            'cancellation_reason' => $status === 'cancelled' ? fake()->sentence() : null,
        ];
    }

    public function draft(): static
    {
        return $this->state([
            'status'       => 'draft',
            'published_at' => null,
        ]);
    }

    public function published(): static
    {
        return $this->state([
            'status'              => 'published',
            'published_at'        => now()->subDays(fake()->numberBetween(1, 14)),
            'submission_deadline' => now()->addDays(fake()->numberBetween(7, 30)),
        ]);
    }

    public function closed(): static
    {
        return $this->state([
            'status'              => 'closed',
            'published_at'        => now()->subDays(30),
            'submission_deadline' => now()->subDays(2),
        ]);
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(['tenant_id' => $tenant->id]);
    }
}
