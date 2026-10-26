<?php

namespace App\Http\Requests\CicloLaboral;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Carga de un archivo del ciclo laboral (escaneo del original firmado,
 * aviso/renuncia, finiquito firmado, anexo de acta). MIME y tamaño
 * restringidos; el nombre original nunca se usa como ruta física.
 */
class ArchivoLaboralRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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
            'descripcion' => ['nullable', 'string', 'max:255'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $tipos = implode(', ', array_map(strtoupper(...), array_filter((array) config('contratos.extensiones_permitidas', ['pdf']), is_string(...))));

        return [
            'archivo.required' => 'Elige el archivo (PDF o imagen).',
            'archivo.file' => 'Elige el archivo (PDF o imagen).',
            'archivo.uploaded' => 'El archivo no se pudo subir (puede exceder el tamaño permitido por el servidor). Intenta de nuevo.',
            'archivo.mimes' => sprintf('Solo se aceptan estos tipos de archivo: %s.', $tipos),
            'archivo.max' => 'El archivo es demasiado grande (máximo :max KB).',
        ];
    }
}
