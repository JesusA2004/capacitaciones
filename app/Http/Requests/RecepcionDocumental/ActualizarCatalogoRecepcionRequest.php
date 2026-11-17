<?php

namespace App\Http\Requests\RecepcionDocumental;

use App\Models\RecepcionDocumentalCatalogo;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Cambio de una entrada del catálogo de «lo que recibo». Todo es opcional
 * (el encendido rápido manda solo `activa`); la coherencia (documentos que
 * la regla sí genera, al menos uno si queda encendida) la valida
 * ConfiguracionCatalogoRecepcionService.
 */
class ActualizarCatalogoRecepcionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $entrada = $this->route('entrada');

        return $entrada instanceof RecepcionDocumentalCatalogo && ($this->user()?->can('update', $entrada) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['sometimes', 'required', 'string', 'max:160'],
            'descripcion' => ['sometimes', 'nullable', 'string', 'max:500'],
            'documentos' => ['sometimes', 'array', 'max:40'],
            'documentos.*' => ['string', 'max:60'],
            'activa' => ['sometimes', 'boolean'],
            'sucursal_ids' => ['sometimes', 'nullable', 'array', 'max:500'],
            'sucursal_ids.*' => ['integer'],
            'empresa_ids' => ['sometimes', 'nullable', 'array', 'max:100'],
            'empresa_ids.*' => ['integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nombre.required' => 'Escribe el nombre de lo que se recibe.',
            'nombre.max' => 'El nombre admite hasta 160 caracteres.',
            'descripcion.max' => 'La descripción admite hasta 500 caracteres.',
        ];
    }

    /**
     * @return array{nombre?: string|null, descripcion?: string|null, documentos?: list<string>|null, activa?: bool|null, sucursal_ids?: list<int>|null, empresa_ids?: list<int>|null}
     */
    public function datos(): array
    {
        $validados = $this->validated();
        $datos = [];

        if (array_key_exists('nombre', $validados)) {
            $datos['nombre'] = is_string($validados['nombre']) ? $validados['nombre'] : null;
        }

        if (array_key_exists('descripcion', $validados)) {
            $datos['descripcion'] = is_string($validados['descripcion']) ? $validados['descripcion'] : null;
        }

        if (array_key_exists('documentos', $validados)) {
            $datos['documentos'] = array_values(array_map('strval', (array) $validados['documentos']));
        }

        if (array_key_exists('activa', $validados)) {
            $datos['activa'] = $this->boolean('activa');
        }

        if (array_key_exists('sucursal_ids', $validados)) {
            $datos['sucursal_ids'] = array_values(array_map('intval', (array) $validados['sucursal_ids']));
        }

        if (array_key_exists('empresa_ids', $validados)) {
            $datos['empresa_ids'] = array_values(array_map('intval', (array) $validados['empresa_ids']));
        }

        return $datos;
    }
}
