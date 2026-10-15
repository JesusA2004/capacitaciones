<?php

namespace App\Http\Requests\Reportes;

use App\Models\RotacionCarga;
use Illuminate\Foundation\Http\FormRequest;

class AnalizarRotacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', RotacionCarga::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'archivo' => ['required', 'file', 'mimes:xlsx,xls', 'max:'.(int) config('rotacion.max_kb', 10240)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'archivo.required' => 'Selecciona el Excel de rotación de personal.',
            'archivo.mimes' => 'El archivo debe ser un Excel (.xlsx o .xls).',
            'archivo.max' => 'El archivo es demasiado grande.',
        ];
    }
}
