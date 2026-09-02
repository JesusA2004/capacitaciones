<?php

namespace Database\Factories;

use App\Enums\EstadoDocumentoGenerado;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GeneratedDocument>
 */
class GeneratedDocumentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_template_id' => DocumentTemplate::factory(),
            'disk' => 'nas',
            'path' => 'documentos-generados/'.fake()->uuid().'.docx',
            'original_name' => 'plantilla.docx',
            'generated_name' => 'documento-generado.docx',
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'size' => 20480,
            'status' => EstadoDocumentoGenerado::Generado->value,
        ];
    }
}
