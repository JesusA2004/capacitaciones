<?php

namespace App\Http\Requests\Administracion;

use App\Enums\TipoPuesto;
use App\Models\Puesto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class ActualizarJerarquiaPuestoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('puesto')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $puesto = $this->route('puesto');
        $puestoId = $puesto instanceof Puesto ? $puesto->id : null;

        return [
            'nivel_jerarquico' => ['nullable', 'integer', 'min:1', 'max:20'],
            'puesto_superior_id' => ['nullable', 'integer', 'exists:puestos,id', Rule::notIn([$puestoId])],
            'puesto_crecimiento_id' => ['nullable', 'integer', 'exists:puestos,id', Rule::notIn([$puestoId])],
            'tipo_puesto' => ['nullable', new Enum(TipoPuesto::class)],
            'esquema_comisiones' => ['nullable', 'string', 'max:255'],
            'requiere_ruta' => ['boolean'],
            'responsabilidades' => ['nullable', 'string', 'max:4000'],
            'requisitos' => ['nullable', 'string', 'max:4000'],
            'respaldos' => ['array'],
            'respaldos.*' => ['integer', 'exists:puestos,id', Rule::notIn([$puestoId])],
        ];
    }
}
