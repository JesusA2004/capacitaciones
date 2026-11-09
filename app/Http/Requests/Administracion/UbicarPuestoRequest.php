<?php

namespace App\Http\Requests\Administracion;

use App\Models\Puesto;
use Illuminate\Foundation\Http\FormRequest;

/**
 * «Ubicar / mover puesto»: superior y departamento nuevos. El ciclo y que el
 * superior esté activo los valida UbicacionPuestoService (misma regla para la
 * vista previa y para aplicar).
 */
class UbicarPuestoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $puesto = $this->route('puesto');

        return $puesto instanceof Puesto && ($this->user()?->can('editarJerarquia', $puesto) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'puesto_superior_id' => ['required', 'integer', 'exists:puestos,id'],
            'departamento_id' => ['nullable', 'integer', 'exists:departamentos,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'puesto_superior_id.required' => 'Elige debajo de quién va el puesto.',
            'puesto_superior_id.exists' => 'Elige un puesto del catálogo.',
            'departamento_id.exists' => 'Elige un departamento del catálogo.',
        ];
    }

    public function superiorId(): int
    {
        return $this->integer('puesto_superior_id');
    }

    public function departamentoId(): ?int
    {
        return $this->filled('departamento_id') ? $this->integer('departamento_id') : null;
    }
}
