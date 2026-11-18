<?php

namespace App\Http\Requests\Capacitacion;

use Illuminate\Foundation\Http\FormRequest;

/**
 * «Subir formato firmado» de la Certificación Mr. Lana (RH-CC-R-001): el
 * escaneo (PDF o imagen) del formato llenado y firmado a pluma y el
 * promedio que trae anotado. Quién puede subirlo lo decide
 * CertificacionMrLanaPolicy::gestionar; el mínimo aprobatorio lo valida
 * CertificacionFormatoService (config/capacitacion.php).
 */
class SubirCertificacionFirmadaRequest extends FormRequest
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
                'max:'.((int) config('expedientes.max_upload_mb') * 1024),
                'mimes:'.implode(',', (array) config('expedientes.extensiones_permitidas')),
            ],
            'promedio' => ['required', 'numeric', 'between:0,100'],
            'confirmo_firmas' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'archivo.required' => 'Adjunta el formato firmado (PDF o imagen).',
            'archivo.file' => 'El formato firmado debe ser un archivo.',
            'archivo.max' => 'El archivo excede el tamaño máximo permitido.',
            'archivo.mimes' => 'Formato no permitido: sube un PDF o una imagen.',
            'promedio.required' => 'Captura el promedio que trae anotado el formato.',
            'promedio.numeric' => 'El promedio debe ser un número.',
            'promedio.between' => 'El promedio va de 0 a 100.',
            'confirmo_firmas.accepted' => 'Confirma que el formato tiene las firmas del gestor y del gerente de sucursal.',
        ];
    }
}
