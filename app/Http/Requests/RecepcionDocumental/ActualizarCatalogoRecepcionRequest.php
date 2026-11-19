<?php

namespace App\Http\Requests\RecepcionDocumental;

use App\Models\RecepcionDocumentalCatalogo;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Cambio de una entrada del catálogo de «lo que recibo». Todo es opcional
 * (el encendido rápido manda solo `activa`); la coherencia (documentos que
 * la regla sí genera, al menos uno si queda encendida, regla base cuando se
 * cuenta por evento, campos que solo aplican a entradas agregadas) la valida
 * ConfiguracionCatalogoRecepcionService. CrearCatalogoRecepcionRequest y
 * SimularCatalogoRecepcionRequest reutilizan estas reglas.
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
            'documentos' => ['sometimes', 'nullable', 'array', 'max:40'],
            'documentos.*' => ['string', 'max:60'],
            'copias' => ['sometimes', 'nullable', 'array', 'max:40'],
            'copias.*' => ['integer', 'min:1', 'max:500'],
            'activa' => ['sometimes', 'boolean'],
            'modo_calculo' => ['sometimes', 'nullable', 'string', 'max:30'],
            'cantidad' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:500'],
            'regla_base' => ['sometimes', 'nullable', 'string', 'max:30'],
            'bloque' => ['sometimes', 'nullable', 'string', 'max:30'],
            'sucursal_ids' => ['sometimes', 'nullable', 'array', 'max:500'],
            'sucursal_ids.*' => ['integer'],
            'empresa_ids' => ['sometimes', 'nullable', 'array', 'max:100'],
            'empresa_ids.*' => ['integer'],
            'departamento_ids' => ['sometimes', 'nullable', 'array', 'max:200'],
            'departamento_ids.*' => ['integer'],
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
            'cantidad.min' => 'La cantidad mínima es 1 papel.',
            'cantidad.max' => 'La cantidad máxima es 500 papeles.',
            'cantidad.integer' => 'La cantidad debe ser un número entero.',
            'copias.*.min' => 'Las copias van de 1 a 500.',
            'copias.*.max' => 'Las copias van de 1 a 500.',
        ];
    }

    /**
     * Solo los campos que llegaron (el servicio distingue «no se mandó» de «vacío»).
     *
     * @return array<string, mixed>
     */
    public function datos(): array
    {
        $validados = $this->validated();
        $datos = [];

        foreach (['nombre', 'descripcion', 'modo_calculo', 'regla_base', 'bloque'] as $campo) {
            if (array_key_exists($campo, $validados)) {
                $datos[$campo] = is_string($validados[$campo]) ? $validados[$campo] : null;
            }
        }

        if (array_key_exists('documentos', $validados)) {
            $datos['documentos'] = array_values(array_map('strval', (array) $validados['documentos']));
        }

        if (array_key_exists('copias', $validados)) {
            $datos['copias'] = array_map('intval', (array) $validados['copias']);
        }

        if (array_key_exists('cantidad', $validados)) {
            $datos['cantidad'] = $validados['cantidad'] !== null ? (int) $validados['cantidad'] : null;
        }

        if (array_key_exists('activa', $validados)) {
            $datos['activa'] = $this->boolean('activa');
        }

        foreach (['sucursal_ids', 'empresa_ids', 'departamento_ids'] as $campo) {
            if (array_key_exists($campo, $validados)) {
                $datos[$campo] = array_values(array_map('intval', (array) $validados[$campo]));
            }
        }

        return $datos;
    }
}
