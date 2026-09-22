<?php

namespace App\Http\Requests\CicloLaboral;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AutorizarPrestamoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('prestamos.autorizar') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'monto_autorizado' => ['required', 'numeric', 'min:1', 'max:9999999'],
            'plazo_autorizado' => ['required', 'integer', 'min:1', 'max:520'],
            'periodicidad' => ['nullable', Rule::in(['semanal', 'quincenal', 'mensual'])],
            'pago_programado' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
