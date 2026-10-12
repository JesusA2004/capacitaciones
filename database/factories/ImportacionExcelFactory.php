<?php

namespace Database\Factories;

use App\Models\ImportacionExcel;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ImportacionExcel>
 */
class ImportacionExcelFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'archivo_hash' => Str::random(64),
            'nombre_archivo' => 'REPORTE_TRABAJADORES_VAC.xlsx',
            'subido_por' => User::factory(),
            'estado' => 'previsualizado',
        ];
    }
}
