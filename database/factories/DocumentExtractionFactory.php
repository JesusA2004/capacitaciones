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
     * colaborador_id es la fuente real de "de quién es esta sugerencia"
     * (DocumentExtraction::colaboradorExpediente(), que usa aplicar()/
     * ignorar()/pendientes()/conflictos()) — los tests existentes solo
     * pasan employee_document_id/user_id, así que se retro-completa aquí
     * igual que EmployeeDocumentFactory.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (DocumentExtraction $extraccion): void {
            if ($extraccion->colaborador_id !== null) {
                return;
            }

            if ($extraccion->employee_document_id !== null) {
                $extraccion->colaborador_id = EmployeeDocument::find($extraccion->employee_document_id)?->colaborador_id;

                return;
            }

            if ($extraccion->user_id !== null) {
                $extraccion->colaborador_id = User::find($extraccion->user_id)?->colaborador_id;
            }
        });
    }

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
