<?php

namespace App\Http\Requests\CicloLaboral;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Programación del pago del finiquito (regional de coordinación). La regla
 * de quién puede programarlo vive en CierreLaboralService::programarPago().
 */
class ProgramarPagoRequest extends FormRequest
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
            'fecha' => ['required', 'date', 'after_or_equal:today'],
            'monto' => ['nullable', 'numeric', 'min:0'],
            'metodo' => ['required', 'string', 'max:60'],
            'responsable_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
