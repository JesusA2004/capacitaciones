<?php

namespace App\Http\Requests\Rh;

use Illuminate\Foundation\Http\FormRequest;

/**
 * LEGACY: escaneo del finiquito firmado (PDF o imagen). Mismas reglas que
 * el resto de los documentos laborales (config/contratos.php) y que
 * ArchivoLaboralRequest, la carga equivalente desde el cierre laboral.
 */
class SubirFiniquitoFirmadoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'archivo' => [
                'required',
                'file',
                'max:'.((int) config('contratos.max_upload_mb', 20) * 1024),
                'mimes:'.implode(',', (array) config('contratos.extensiones_permitidas', ['pdf'])),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $tipos = implode(', ', array_map(strtoupper(...), array_filter((array) config('contratos.extensiones_permitidas', ['pdf']), is_string(...))));

        return [
            'archivo.required' => 'Elige el archivo del documento firmado (PDF o imagen).',
            'archivo.file' => 'Elige el archivo del documento firmado (PDF o imagen).',
            'archivo.uploaded' => 'El archivo no se pudo subir (puede exceder el tamaño permitido por el servidor). Intenta de nuevo.',
            'archivo.mimes' => sprintf('Solo se aceptan estos tipos de archivo: %s.', $tipos),
            'archivo.max' => 'El archivo es demasiado grande (máximo :max KB).',
        ];
    }
}
