<?php

namespace App\Http\Requests\Contratos;

use App\Services\Contratos\ContratoFirmadoService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * «Subir contrato firmado» (web y API). Quién puede subirlo — permiso,
 * alcance y la regla del contrato de un Gerente de Sucursal — lo decide
 * ContratoFirmadoService (403 con el motivo); aquí solo se valida la forma.
 */
class SubirContratoFirmadoRequest extends FormRequest
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
            'clave' => ['required', 'string', Rule::in(app(ContratoFirmadoService::class)->clavesAdmitidas())],
            'archivo' => [
                'required',
                'file',
                'max:'.((int) config('expedientes.max_upload_mb') * 1024),
                'mimes:'.implode(',', (array) config('expedientes.extensiones_permitidas')),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'clave.required' => 'Indica qué contrato firmado vas a subir.',
            'clave.in' => 'Ese documento no admite la carga del contrato firmado.',
            'archivo.required' => 'Adjunta el contrato firmado (PDF o imagen).',
            'archivo.file' => 'El contrato firmado debe ser un archivo.',
            'archivo.max' => 'El archivo excede el tamaño máximo permitido.',
            'archivo.mimes' => 'Formato no permitido: sube un PDF o una imagen.',
        ];
    }
}
