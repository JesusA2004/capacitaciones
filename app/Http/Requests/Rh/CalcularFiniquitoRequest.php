<?php

namespace App\Http\Requests\Rh;

use Illuminate\Foundation\Http\FormRequest;

/**
 * La autorización real vive en el controlador (FiniquitoCalculoPolicy::calcular/
 * editarAjustes según sea el primer cálculo o un recálculo) — esta request
 * solo valida la forma del payload.
 */
class CalcularFiniquitoRequest extends FormRequest
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
            'sueldo_mensual' => ['required', 'numeric', 'min:1'],
            'sueldo_pendiente' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
