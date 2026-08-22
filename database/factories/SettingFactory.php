<?php

namespace Database\Factories;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Setting> */
class SettingFactory extends Factory
{
    protected $model = Setting::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $type = $this->faker->randomElement([
            Setting::TYPE_STRING,
            Setting::TYPE_TEXT,
            Setting::TYPE_BOOLEAN,
            Setting::TYPE_NUMBER,
            Setting::TYPE_SELECT,
            Setting::TYPE_SECRET,
            Setting::TYPE_JSON,
        ]);

        return [
            'key' => str($this->faker->unique()->slug(2, false))->replace('-', '_')->toString(),
            'value' => $this->valueForType($type),
            'group' => $this->faker->randomElement(['general', 'appearance', 'seo', 'localization', 'system', 'sms', 'payment', 'email']),
            'type' => $type,
            'label' => $this->faker->words(3, true),
            'options' => $type === Setting::TYPE_SELECT ? ['a' => 'Option A', 'b' => 'Option B'] : null,
            'status' => $this->faker->boolean(90),
            'created_by' => User::factory(),
            'updated_by' => User::factory(),
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => false]);
    }

    public function group(string $group): static
    {
        return $this->state(fn () => ['group' => $group]);
    }

    protected function valueForType(string $type): mixed
    {
        return match ($type) {
            Setting::TYPE_BOOLEAN => $this->faker->boolean ? '1' : '0',
            Setting::TYPE_NUMBER => (string) $this->faker->numberBetween(1, 100),
            Setting::TYPE_SELECT => $this->faker->randomElement(['a', 'b']),
            Setting::TYPE_SECRET => $this->faker->password(16),
            Setting::TYPE_JSON => json_encode([$this->faker->word() => $this->faker->word()]),
            default => $this->faker->sentence(),
        };
    }
}
