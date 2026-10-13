<?php

namespace App\Http\Requests\Puestos;

use App\Models\PerfilPuesto;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Perfil de puesto: textos cortos y listas de renglones (máx. 30 por lista).
 */
class GuardarPerfilPuestoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('perfiles_puesto.editar') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $reglas = [
            'objetivo' => ['nullable', 'string', 'max:2000'],
            'escolaridad' => ['nullable', 'string', 'max:255'],
            'experiencia' => ['nullable', 'string', 'max:2000'],
        ];

        foreach (PerfilPuesto::LISTAS as $lista) {
            $reglas[$lista] = ['nullable', 'array', 'max:30'];
            $reglas[$lista.'.*'] = ['nullable', 'string', 'max:300'];
        }

        return $reglas;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            '*.max' => 'Máximo 30 renglones por sección.',
            '*.*.max' => 'Cada renglón puede tener hasta 300 caracteres.',
        ];
    }
}
