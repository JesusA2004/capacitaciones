<?php

namespace App\Http\Requests\CicloLaboral;

use App\Enums\TipoBaja;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IniciarCierreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tipo_baja' => ['required', Rule::enum(TipoBaja::class)],
            'motivo' => ['required', 'string', 'max:2000'],
            'fecha_efectiva' => ['required', 'date'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
