<?php

namespace App\Http\Requests\CicloLaboral;

use App\Enums\TipoActa;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarActaRequest extends FormRequest
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
        $requerido = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'tipo' => [$this->isMethod('post') ? 'required' : 'prohibited', Rule::enum(TipoActa::class)],
            'fecha' => [$requerido, 'date', 'before_or_equal:today'],
            'hora' => ['nullable', 'date_format:H:i'],
            'lugar' => ['nullable', 'string', 'max:255'],
            'sucursal_id' => ['nullable', 'integer', 'exists:sucursales,id'],
            'hechos' => [$requerido, 'string', 'max:20000'],
            'responsable_id' => ['nullable', 'integer', 'exists:users,id'],
            'testigos' => ['nullable', 'array', 'max:5'],
            'testigos.*.nombre' => ['required_with:testigos', 'string', 'max:160'],
            'testigos.*.puesto' => ['nullable', 'string', 'max:120'],
            'testigos.*.colaborador_id' => ['nullable', 'integer', 'exists:colaboradores,id'],
            'declaraciones' => ['nullable', 'array', 'max:20'],
            'declaraciones.*.persona' => ['required_with:declaraciones', 'string', 'max:160'],
            'declaraciones.*.declaracion' => ['required_with:declaraciones', 'string', 'max:5000'],
        ];
    }
}
