<?php

namespace App\Http\Requests\CicloLaboral;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Operaciones del flujo documental que solo llevan datos: impresión,
 * firma física (huella/testigos), envío (paquetería/guía/comprobante),
 * recepción, archivo, cancelación.
 */
class OperacionDocumentoRequest extends FormRequest
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
            'observaciones' => ['nullable', 'string', 'max:1000'],
            'huella_registrada' => ['sometimes', 'boolean'],
            'testigos' => ['nullable', 'array', 'max:5'],
            'testigos.*.nombre' => ['required_with:testigos', 'string', 'max:160'],
            'testigos.*.puesto' => ['nullable', 'string', 'max:120'],
            'paqueteria' => ['sometimes', 'required', 'string', 'max:80'],
            'numero_guia' => ['sometimes', 'required', 'string', 'max:80'],
            'comprobante' => [
                'nullable',
                'file',
                'max:'.((int) config('contratos.max_upload_mb', 20) * 1024),
                'mimes:'.implode(',', (array) config('contratos.extensiones_permitidas', ['pdf'])),
            ],
            'motivo' => ['sometimes', 'required', 'string', 'max:1000'],
        ];
    }
}
