<?php

namespace Database\Factories;

use App\Models\AltaDigital;
use App\Models\AltaDigitalDocumento;
use App\Models\DocumentType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AltaDigitalDocumento>
 */
class AltaDigitalDocumentoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'alta_digital_id' => AltaDigital::factory(),
            'document_type_id' => DocumentType::factory(),
            'disk' => 'nas',
            'path' => 'altas/test/documentos/'.fake()->uuid().'.pdf',
            'original_name' => 'documento.pdf',
            'mime' => 'application/pdf',
            'size' => 1024,
        ];
    }
}
