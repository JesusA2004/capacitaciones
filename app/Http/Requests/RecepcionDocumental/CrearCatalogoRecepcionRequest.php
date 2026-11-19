<?php

namespace App\Http\Requests\RecepcionDocumental;

use App\Models\RecepcionDocumentalCatalogo;

/**
 * Agregar algo que debe llegar cada mes al catálogo de «lo que recibo»
 * (mismo permiso `recepcion_documental.configurar`). Nombre y forma de
 * cálculo son obligatorios; el resto lo valida el servicio.
 */
class CrearCatalogoRecepcionRequest extends ActualizarCatalogoRecepcionRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('configurar', RecepcionDocumentalCatalogo::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'nombre' => ['required', 'string', 'max:160'],
            'modo_calculo' => ['required', 'string', 'max:30'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'modo_calculo.required' => 'Elige cómo se calcula cuántos papeles llegan.',
        ];
    }
}
