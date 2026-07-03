<?php

namespace Database\Factories;

use App\Models\Certificado;
use App\Models\Curso;
use App\Models\InscripcionCurso;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Certificado>
 */
class CertificadoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'folio' => strtoupper(Str::random(10)),
            'user_id' => User::factory(),
            'curso_id' => Curso::factory(),
            'inscripcion_curso_id' => InscripcionCurso::factory(),
            'emitido_en' => now(),
        ];
    }
}
