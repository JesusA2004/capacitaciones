<?php

namespace Database\Factories;

use App\Models\BirthdayGreeting;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BirthdayGreeting>
 */
class BirthdayGreetingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'birthday_phrase_id' => null,
            'fecha' => now()->toDateString(),
            'nombre_mostrado' => $this->faker->firstName(),
            'frase' => 'Hoy celebramos tu vida y la energía que aportas al equipo. ¡Feliz cumpleaños!',
            'card_path' => null,
            'enviada_at' => null,
            'enviada_por_id' => null,
            'auto_generada' => true,
            'metadata' => null,
        ];
    }
}
