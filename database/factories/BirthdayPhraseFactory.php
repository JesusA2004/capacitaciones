<?php

namespace Database\Factories;

use App\Models\BirthdayPhrase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BirthdayPhrase>
 */
class BirthdayPhraseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'texto' => 'Que este nuevo año de vida te traiga salud, alegría y nuevos logros. Gracias por ser parte de MR. LANA.',
            'categoria' => 'general',
            'activo' => true,
            'orden' => $this->faker->numberBetween(1, 100),
            'usado_count' => 0,
            'ultimo_uso_at' => null,
        ];
    }

    public function inactiva(): static
    {
        return $this->state(fn () => ['activo' => false]);
    }
}
