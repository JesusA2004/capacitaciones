<?php

namespace App\Http\Requests\Contratos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Datos faltantes capturados desde el módulo «Contratos». Las columnas
 * permitidas y sus reglas finas las valida DocumentoProcesoService::
 * completarDatos (misma lista blanca que el modal de Documentos del proceso).
 */
class CompletarDatosContratoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('contratos.ver') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'datos' => ['required', 'array', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'datos.required' => 'Captura al menos un dato faltante.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function datos(): array
    {
        $datos = $this->validated('datos');

        return is_array($datos) ? $datos : [];
    }
}
