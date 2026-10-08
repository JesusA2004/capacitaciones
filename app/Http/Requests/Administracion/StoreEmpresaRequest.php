<?php

namespace App\Http\Requests\Administracion;

use App\Models\Empresa;
use Illuminate\Foundation\Http\FormRequest;

class StoreEmpresaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Empresa::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:150'],
            'razon_social' => ['nullable', 'string', 'max:255'],
            'rfc' => ['nullable', 'string', 'max:13'],
            'logo' => ['nullable', 'image', 'max:1024'],
            'activo' => ['boolean'],
            // Datos del patrón en los documentos laborales (contratos, convenios…).
            // Vacíos = el documento usa el valor predeterminado del registro
            // jurídico y la vista previa lo advierte.
            'domicilio_fiscal' => ['nullable', 'string', 'max:500'],
            // Encabezado de los formatos oficiales (recibo, finiquito, permiso).
            'registro_patronal' => ['nullable', 'string', 'max:20'],
            'codigo_postal_fiscal' => ['nullable', 'string', 'max:10'],
            'ciudad_firma' => ['nullable', 'string', 'max:191'],
            'representante_legal_nombre' => ['nullable', 'string', 'max:191'],
            'representante_legal_cargo' => ['nullable', 'string', 'max:191'],
        ];
    }
}
