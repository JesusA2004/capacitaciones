<?php

namespace Database\Factories;

use App\Models\MobileDevice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MobileDevice>
 */
class MobileDeviceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'push_token' => 'ExponentPushToken['.$this->faker->unique()->uuid().']',
            'platform' => $this->faker->randomElement(['ios', 'android', 'web']),
            'device_name' => $this->faker->words(2, true),
            'app_version' => '1.0.0',
            'last_seen_at' => now(),
        ];
    }
}
