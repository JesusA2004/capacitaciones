<?php

namespace App\Http\Requests\CicloLaboral;

use App\Models\ReciboNomina;
use Illuminate\Foundation\Http\FormRequest;

class ImportarRecibosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('importar', ReciboNomina::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'archivo' => ['required', 'file', 'max:5120', 'mimes:csv,txt,xlsx'],
            'periodo_inicio' => ['required', 'date'],
            'periodo_fin' => ['required', 'date', 'after_or_equal:periodo_inicio'],
            'fecha_pago' => ['nullable', 'date'],
            'simular' => ['sometimes', 'boolean'],
        ];
    }
}
