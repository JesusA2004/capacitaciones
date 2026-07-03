<?php

namespace Database\Factories;

use App\Models\IntervaloVideoVisto;
use App\Models\Leccion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntervaloVideoVisto>
 */
class IntervaloVideoVistoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'leccion_id' => Leccion::factory(),
            'inicio_segundo' => 0,
            'fin_segundo' => 10,
        ];
    }
}
