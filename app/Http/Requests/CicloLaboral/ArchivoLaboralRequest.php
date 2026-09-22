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
}
