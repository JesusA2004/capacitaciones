<?php

namespace App\Http\Requests\Rh;

use Illuminate\Foundation\Http\FormRequest;

class AplicarExtraccionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('rh.documentos.extraccion.aplicar') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // RH puede aceptar el valor detectado tal cual o corregirlo a
            // mano antes de enviarlo — aqui no se distingue, ambos casos
            // son una decision explicita de RH.
            'valores' => ['required', 'array', 'min:1'],
            'valores.curp' => ['sometimes', 'string', 'max:18'],
            'valores.rfc' => ['sometimes', 'string', 'max:13'],
            'valores.nss' => ['sometimes', 'string', 'max:11'],
            'valores.fecha_nacimiento' => ['sometimes', 'date_format:d/m/Y'],
        ];
    }
}
