<?php

namespace Database\Factories;

use App\Enums\EstadoExtraccion;
use App\Models\DocumentExtraction;
use App\Models\EmployeeDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentExtraction>
 */
class DocumentExtractionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_document_id' => EmployeeDocument::factory(),
            'user_id' => User::factory(),
            'status' => EstadoExtraccion::Pendiente->value,
        ];
    }

    public function procesada(): static
    {
        return $this->state(fn () => [
            'status' => EstadoExtraccion::Procesado->value,
            'extracted_text' => 'CURP: XXXX000000HXXXXX00 RFC: XXXX000000XX0',
            'extracted_data' => ['curp' => 'XXXX000000HXXXXX00'],
            'confidence' => ['curp' => 'alta'],
            'differences' => ['curp' => ['detectado' => 'XXXX000000HXXXXX00', 'actual' => null]],
        ]);
    }

    public function fallida(): static
    {
        return $this->state(fn () => [
            'status' => EstadoExtraccion::Fallido->value,
            'error_message' => 'No se pudo leer el archivo.',
        ]);
    }
}
