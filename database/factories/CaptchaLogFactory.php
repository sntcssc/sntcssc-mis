<?php

namespace Database\Factories;

use App\Models\CaptchaLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CaptchaLog>
 */
class CaptchaLogFactory extends Factory
{
    protected $model = CaptchaLog::class;

    public function definition(): array
    {
        $status = fake()->randomElement([
            CaptchaLog::STATUS_PASSED,
            CaptchaLog::STATUS_PASSED,
            CaptchaLog::STATUS_PASSED,
            CaptchaLog::STATUS_FAILED,
        ]);

        return [
            'session_id' => fake()->uuid(),
            'driver' => fake()->randomElement([CaptchaLog::DRIVER_CUSTOM, CaptchaLog::DRIVER_GOOGLE]),
            'action' => fake()->randomElement([
                CaptchaLog::ACTION_LOGIN,
                CaptchaLog::ACTION_REGISTER,
                CaptchaLog::ACTION_FORGOT_PASSWORD,
                CaptchaLog::ACTION_CONTACT_US,
            ]),
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'status' => $status,
            'score' => $status === CaptchaLog::STATUS_PASSED ? fake()->randomFloat(2, 0.7, 0.99) : fake()->randomFloat(2, 0.1, 0.4),
            'error_code' => $status === CaptchaLog::STATUS_PASSED ? null : 'invalid-captcha-code',
            'response_time_ms' => fake()->numberBetween(120, 850),
            'user_id' => null,
            'metadata' => ['browser' => 'Chrome', 'os' => 'Windows'],
        ];
    }

    public function passed(): static
    {
        return $this->state(fn () => [
            'status' => CaptchaLog::STATUS_PASSED,
            'error_code' => null,
            'score' => 0.9,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => CaptchaLog::STATUS_FAILED,
            'error_code' => 'invalid-captcha-answer',
            'score' => 0.2,
        ]);
    }
}
