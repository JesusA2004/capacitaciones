<?php

namespace Database\Factories;

use App\Models\Empresa;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Empresa>
 */
class EmpresaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Datos del patrón completos: los documentos legales ya no los
        // completan con un supuesto (una prueba de «falta el dato» los pone
        // en null explícitamente).
        return [
            'nombre' => fake()->unique()->company(),
            'razon_social' => fake()->company().', S.A. de C.V.',
            'rfc' => strtoupper(fake()->bothify('???######???')),
            'registro_patronal' => strtoupper(fake()->bothify('?##########')),
            'domicilio_fiscal' => 'Subida al Club número 114, colonia Reforma, C.P. 62260, Cuernavaca, Morelos',
            'codigo_postal_fiscal' => '62260',
            'representante_legal_nombre' => 'Lesli Maribel Rodríguez Herrera',
            'representante_legal_cargo' => 'Representante Legal',
            'activo' => true,
        ];
    }
}
