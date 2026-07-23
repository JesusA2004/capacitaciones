<?php

namespace Database\Factories;

use App\Enums\EstadoDocumento;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeDocument>
 */
class EmployeeDocumentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'document_type_id' => DocumentType::factory(),
            'disk' => 'nas',
            'path' => 'expedientes/test/'.fake()->uuid().'.pdf',
            'original_name' => 'documento.pdf',
            'stored_name' => fake()->uuid().'.pdf',
            'mime' => 'application/pdf',
            'extension' => 'pdf',
            'size' => fake()->numberBetween(1000, 500000),
            'hash' => hash('sha256', fake()->uuid()),
            'version' => 1,
            'status' => EstadoDocumento::Pendiente->value,
        ];
    }

    public function aprobado(): static
    {
        return $this->state(fn () => [
            'status' => EstadoDocumento::Aprobado->value,
            'reviewed_at' => now(),
        ]);
    }
}
