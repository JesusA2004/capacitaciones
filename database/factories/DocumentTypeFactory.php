<?php

namespace Database\Factories;

use App\Models\DocumentType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentType>
 */
class DocumentTypeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $palabras = fake()->unique()->words(2);
        $nombre = is_array($palabras) ? implode(' ', $palabras) : $palabras;

        return [
            'nombre' => ucfirst($nombre),
            'clave' => str()->slug($nombre),
            'descripcion' => fake()->sentence(),
            'requerido' => true,
            'aplica_alta' => true,
            'activo' => true,
        ];
    }
}
