<?php

namespace Database\Factories;

use App\Models\ProductType;
use App\Models\QaProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QaProfile>
 */
class QaProfileFactory extends Factory
{
    protected $model = QaProfile::class;

    public function definition(): array
    {
        return [
            'product_type_id' => ProductType::factory(),
            'product_type_code' => fn (array $attributes) => ProductType::query()
                ->whereKey($attributes['product_type_id'])
                ->value('code'),
            'required' => true,
            'checklist_json' => [
                ['id' => 'dim', 'label' => 'Wymiar', 'type' => 'bool', 'required' => true],
            ],
            'active' => true,
            'version' => 1,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }

    public function notRequired(): static
    {
        return $this->state(fn () => ['required' => false]);
    }
}
