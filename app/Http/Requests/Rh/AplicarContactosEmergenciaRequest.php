<?php

namespace App\Http\Requests\Rh;

use Illuminate\Foundation\Http\FormRequest;

class AplicarContactosEmergenciaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('expedientes.editar') ?? false;
    }

    /**
     * `filas`: números de fila del Excel que RH dejó marcadas en la vista
     * previa (si no se manda, todas las aplicables). `confirmadas`: filas
     * cuyo teléfono sin 10 dígitos RH confirmó guardar tal cual.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'filas' => ['nullable', 'array'],
            'filas.*' => ['integer', 'min:0'],
            'confirmadas' => ['nullable', 'array'],
            'confirmadas.*' => ['integer', 'min:0'],
        ];
    }
}
