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
     * colaborador_id es la fuente real de "de quién es este documento" (ver
     * App\Models\EmployeeDocument::colaborador()) — la mayoría de los tests
     * existentes solo pasan `user_id` explícito, así que se retro-completa
     * aquí desde el colaborador enlazado a ese User en vez de obligar a
     * tocar cada test uno por uno.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (EmployeeDocument $documento): void {
            if ($documento->colaborador_id === null && $documento->user_id !== null) {
                $documento->colaborador_id = User::find($documento->user_id)?->colaborador_id;
            }
        });
    }

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
